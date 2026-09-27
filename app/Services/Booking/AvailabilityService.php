<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BusinessHour;
use App\Models\ClosedDay;
use App\Models\Staff;
use App\Models\StaffTimeOff;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tính khung giờ còn trống của thợ.
 *
 * Khoảng thời gian được biểu diễn bằng cặp timestamp [start, end) để cộng / trừ cho gọn.
 * Dữ liệu của cả khoảng ngày được load một lần rồi tính trong bộ nhớ.
 */
class AvailabilityService
{
    public function __construct(private BookingSettings $settings) {}

    /**
     * Thợ đang nhận lịch và làm được TẤT CẢ dịch vụ đã chọn.
     * Relation services chỉ chứa các dịch vụ đã chọn (kèm pivot giá / thời lượng riêng).
     *
     * @param  list<int>  $serviceIds
     * @return EloquentCollection<int, Staff>
     */
    public function eligibleStaff(array $serviceIds, ?int $staffId = null): EloquentCollection
    {
        $serviceIds = array_values(array_unique($serviceIds));
        $onlySelected = fn ($q) => $q->whereIn('services.id', $serviceIds)->where('services.is_active', true);

        return Staff::bookable()
            ->when($staffId, fn ($q) => $q->whereKey($staffId))
            ->whereHas('services', $onlySelected, '=', count($serviceIds))
            ->with(['services' => $onlySelected, 'schedules'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Thời lượng làm các dịch vụ (theo thời lượng riêng của thợ nếu có) và buffer của dịch vụ cuối.
     * $staff->services phải được load sẵn và chứa đủ các dịch vụ.
     *
     * @param  list<int>  $serviceIds  theo thứ tự làm
     * @return array{duration: int, buffer: int}
     */
    public function durationFor(Staff $staff, array $serviceIds): array
    {
        $services = $staff->services->keyBy('id');
        $duration = 0;

        foreach ($serviceIds as $id) {
            $duration += $services[$id]->pivot->custom_duration_minutes ?? $services[$id]->duration_minutes;
        }

        return ['duration' => $duration, 'buffer' => $services[end($serviceIds)]->buffer_minutes];
    }

    /**
     * Các khung giờ còn trống trong ngày và những thợ trống ở mỗi khung.
     *
     * @param  list<int>  $serviceIds
     * @param  bool  $ignoreLeadTime  admin tạo lịch hộ (khách vãng lai): chỉ bỏ các khung đã qua
     * @param  int|null  $ignoreBookingId  khi đổi lịch: không tính chỗ mà chính booking đó đang chiếm
     * @return array<string, list<int>> ['09:00' => [staffId, ...], ...]
     */
    public function slotsForDate(Carbon $date, array $serviceIds, ?int $staffId = null, bool $ignoreLeadTime = false, ?int $ignoreBookingId = null): array
    {
        return $this->computeRange($date, $date, $serviceIds, $staffId, $ignoreLeadTime, $ignoreBookingId)[$date->toDateString()] ?? [];
    }

    /**
     * Các ngày (Y-m-d) từ hôm nay tới max_advance_days còn ít nhất một khung giờ trống.
     *
     * @param  list<int>  $serviceIds
     * @return list<string>
     */
    public function availableDates(array $serviceIds, ?int $staffId = null): array
    {
        $slots = $this->computeRange(today(), today()->addDays($this->settings->maxAdvanceDays()), $serviceIds, $staffId);

        return array_keys(array_filter($slots));
    }

    /**
     * Thợ trống tại đúng thời điểm bắt đầu (bước chọn nhân viên sau khi chọn giờ).
     *
     * @param  list<int>  $serviceIds
     * @return EloquentCollection<int, Staff>
     */
    public function staffAvailableAt(Carbon $startAt, array $serviceIds): EloquentCollection
    {
        $ids = $this->slotsForDate($startAt, $serviceIds)[$startAt->format('H:i')] ?? [];

        return $this->eligibleStaff($serviceIds)->whereIn('id', $ids)->values();
    }

    /**
     * [start, occupiedUntil) nằm trọn trong giờ làm của thợ: không phải ngày nghỉ,
     * trong giờ mở cửa, trong ca làm và không trùng lịch nghỉ. Không xét các booking khác.
     */
    public function fitsWorkingHours(Staff $staff, Carbon $startAt, Carbon $occupiedUntil): bool
    {
        $day = $startAt->copy()->startOfDay();

        if (! $startAt->isSameDay($occupiedUntil->copy()->subSecond()) || $this->isClosed($day, $this->closedDates($day, $day))) {
            return false;
        }

        $timeOffs = StaffTimeOff::where('staff_id', $staff->id)
            ->where('start_at', '<', $occupiedUntil)
            ->where('end_at', '>', $startAt)
            ->get();

        $free = $this->subtract(
            $this->workingIntervals($staff, $day, $this->businessHours()),
            $this->toIntervals($timeOffs, 'start_at', 'end_at'),
        );

        foreach ($free as [$from, $to]) {
            if ($startAt->timestamp >= $from && $occupiedUntil->timestamp <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Thời điểm tiệm đang / sắp mở cửa gần nhất tính từ $at (dùng tính hạn duyệt cho booking đặt lúc nửa đêm).
     */
    public function nextOpeningMoment(Carbon $at): Carbon
    {
        $hours = $this->businessHours();
        $closedDates = $this->closedDates($at->copy()->startOfDay(), $at->copy()->addDays(7));

        for ($day = $at->copy()->startOfDay(), $i = 0; $i <= 7; $i++, $day->addDay()) {
            $businessHour = $hours->get($day->dayOfWeek);

            if ($this->isClosed($day, $closedDates) || ! $businessHour || $businessHour->is_closed || ! $businessHour->open_time) {
                continue;
            }

            $open = $day->copy()->setTimeFromTimeString($businessHour->open_time);
            $close = $day->copy()->setTimeFromTimeString($businessHour->close_time);

            if ($at->lt($open)) {
                return $open;
            }
            if ($at->lt($close)) {
                return $at->copy();
            }
        }

        return $at->copy();
    }

    /**
     * Thợ không có booking nào đang giữ chỗ giao với [startAt, occupiedUntil).
     *
     * Gọi sau khi đã khóa dòng thợ. Connection chạy READ COMMITTED nên đã thấy booking mà request khác
     * vừa commit; FOR SHARE là lớp phòng thủ thêm nếu isolation level bị đổi về REPEATABLE READ.
     */
    public function isStaffFree(Staff $staff, Carbon $startAt, Carbon $occupiedUntil, ?int $ignoreBookingId = null): bool
    {
        return Booking::where('staff_id', $staff->id)
            ->blocking()
            ->overlapping($startAt, $occupiedUntil)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->sharedLock()
            ->first(['id']) === null;
    }

    /**
     * @param  list<int>  $serviceIds
     * @return array<string, array<string, list<int>>> ['Y-m-d' => ['H:i' => [staffId, ...]]]
     */
    private function computeRange(Carbon $from, Carbon $to, array $serviceIds, ?int $staffId, bool $ignoreLeadTime = false, ?int $ignoreBookingId = null): array
    {
        $serviceIds = array_values(array_unique($serviceIds));
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay()->min(today()->addDays($this->settings->maxAdvanceDays()));

        $staffList = $serviceIds ? $this->eligibleStaff($serviceIds, $staffId) : new EloquentCollection;

        if ($staffList->isEmpty() || $from->gt($to)) {
            return [];
        }

        $rangeStart = $from->copy();
        $rangeEnd = $to->copy()->endOfDay();
        $staffIds = $staffList->modelKeys();

        $closedDates = $this->closedDates($from, $to);
        $hours = $this->businessHours();
        $timeOffs = StaffTimeOff::whereIn('staff_id', $staffIds)
            ->where('start_at', '<', $rangeEnd)
            ->where('end_at', '>', $rangeStart)
            ->get()
            ->groupBy('staff_id');
        $bookings = Booking::whereIn('staff_id', $staffIds)
            ->blocking()
            ->overlapping($rangeStart, $rangeEnd)
            ->when($ignoreBookingId, fn ($q) => $q->whereKeyNot($ignoreBookingId))
            ->get(['id', 'staff_id', 'start_at', 'occupied_until'])
            ->groupBy('staff_id');

        $earliest = now()->addMinutes($ignoreLeadTime ? 0 : $this->settings->minLeadMinutes())->timestamp;
        $step = $this->settings->slotInterval() * 60;
        $result = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if ($this->isClosed($day, $closedDates)) {
                continue;
            }

            $slots = [];
            $dayStart = $day->timestamp;

            foreach ($staffList as $staff) {
                ['duration' => $duration, 'buffer' => $buffer] = $this->durationFor($staff, $serviceIds);
                $needed = ($duration + $buffer) * 60;

                $free = $this->subtract(
                    $this->workingIntervals($staff, $day, $hours),
                    [
                        ...$this->toIntervals($timeOffs->get($staff->id, collect()), 'start_at', 'end_at'),
                        ...$this->toIntervals($bookings->get($staff->id, collect()), 'start_at', 'occupied_until'),
                    ],
                );

                foreach ($free as [$start, $end]) {
                    // Làm tròn lên theo lưới slot tính từ 00:00 (09:00, 09:15, ...)
                    $t = $dayStart + (int) ceil((max($start, $earliest) - $dayStart) / $step) * $step;

                    for (; $t + $needed <= $end; $t += $step) {
                        $slots[date('H:i', $t)][] = $staff->id;
                    }
                }
            }

            if ($slots) {
                ksort($slots);
                $result[$day->toDateString()] = $slots;
            }
        }

        return $result;
    }

    /**
     * Ca làm của thợ trong ngày, cắt theo giờ mở cửa của tiệm.
     *
     * @param  Collection<int, BusinessHour>  $hours
     * @return list<array{int, int}>
     */
    private function workingIntervals(Staff $staff, Carbon $day, Collection $hours): array
    {
        $businessHour = $hours->get($day->dayOfWeek);

        if (! $businessHour || $businessHour->is_closed || ! $businessHour->open_time || ! $businessHour->close_time) {
            return [];
        }

        $open = $day->copy()->setTimeFromTimeString($businessHour->open_time)->timestamp;
        $close = $day->copy()->setTimeFromTimeString($businessHour->close_time)->timestamp;
        $intervals = [];

        foreach ($staff->schedules->where('day_of_week', $day->dayOfWeek) as $shift) {
            $start = max($open, $day->copy()->setTimeFromTimeString($shift->start_time)->timestamp);
            $end = min($close, $day->copy()->setTimeFromTimeString($shift->end_time)->timestamp);

            if ($start < $end) {
                $intervals[] = [$start, $end];
            }
        }

        sort($intervals);

        return $intervals;
    }

    /**
     * Trừ các khoảng bận khỏi các khoảng rảnh.
     *
     * @param  list<array{int, int}>  $free
     * @param  list<array{int, int}>  $busy
     * @return list<array{int, int}>
     */
    private function subtract(array $free, array $busy): array
    {
        foreach ($busy as [$busyStart, $busyEnd]) {
            $next = [];

            foreach ($free as [$start, $end]) {
                if ($busyEnd <= $start || $busyStart >= $end) {
                    $next[] = [$start, $end];

                    continue;
                }
                if ($busyStart > $start) {
                    $next[] = [$start, $busyStart];
                }
                if ($busyEnd < $end) {
                    $next[] = [$busyEnd, $end];
                }
            }

            $free = $next;
        }

        return $free;
    }

    /**
     * @return list<array{int, int}>
     */
    private function toIntervals(Collection $rows, string $startColumn, string $endColumn): array
    {
        return $rows->map(fn ($row) => [$row->{$startColumn}->timestamp, $row->{$endColumn}->timestamp])->values()->all();
    }

    /**
     * @return Collection<int, BusinessHour>
     */
    private function businessHours(): Collection
    {
        return BusinessHour::all()->keyBy('day_of_week');
    }

    /**
     * @return array<string, true>
     */
    private function closedDates(Carbon $from, Carbon $to): array
    {
        return ClosedDay::whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->pluck('date')
            ->mapWithKeys(fn ($date) => [$date->toDateString() => true])
            ->all();
    }

    /**
     * @param  array<string, true>  $closedDates
     */
    private function isClosed(Carbon $day, array $closedDates): bool
    {
        return isset($closedDates[$day->toDateString()]);
    }
}
