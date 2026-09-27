<?php

namespace App\Filament\Pages;

use App\Enums\BookingStatus;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Models\BusinessHour;
use App\Models\ClosedDay;
use App\Models\Staff;
use App\Models\StaffTimeOff;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Lịch trong ngày theo từng thợ: mỗi cột một thợ, trục dọc là giờ.
 */
class Schedule extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Bookings;

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Lịch theo ngày';

    protected string $view = 'filament.pages.schedule';

    /** Chiều cao (px) cho mỗi phút trên lưới lịch */
    public const PX_PER_MINUTE = 1.6;

    #[Url]
    public string $date = '';

    #[Url]
    public bool $showCancelled = false;

    public function mount(): void
    {
        $this->date = $this->date ?: today()->toDateString();
    }

    public function previousDay(): void
    {
        $this->date = $this->day()->subDay()->toDateString();
    }

    public function nextDay(): void
    {
        $this->date = $this->day()->addDay()->toDateString();
    }

    public function goToday(): void
    {
        $this->date = today()->toDateString();
    }

    public function getHeading(): string
    {
        return 'Lịch '.$this->day()->translatedFormat('l, d/m/Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Tạo lịch hộ khách')
                ->icon(Heroicon::OutlinedPlus)
                ->url(BookingResource::getUrl('create')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $day = $this->day();
        $businessHour = BusinessHour::firstWhere('day_of_week', $day->dayOfWeek);
        $closedDay = ClosedDay::whereDate('date', $day)->first();

        $bookings = Booking::with(['customer', 'items'])
            ->whereBetween('start_at', [$day, $day->copy()->endOfDay()])
            ->when(! $this->showCancelled, fn ($q) => $q->whereNotIn('status', [BookingStatus::Cancelled, BookingStatus::Rejected]))
            ->orderBy('start_at')
            ->get();

        $staffList = Staff::where('is_active', true)
            ->with(['schedules' => fn ($q) => $q->where('day_of_week', $day->dayOfWeek)])
            ->orderBy('sort_order')
            ->get();

        // Khung hiển thị: giờ mở cửa, nới ra nếu có booking nằm ngoài
        $open = $businessHour && ! $businessHour->is_closed ? $this->minutes($businessHour->open_time) : 8 * 60;
        $close = $businessHour && ! $businessHour->is_closed ? $this->minutes($businessHour->close_time) : 21 * 60;
        foreach ($bookings as $booking) {
            $open = min($open, $booking->start_at->hour * 60 + $booking->start_at->minute);
            $close = max($close, $booking->occupied_until->isSameDay($day) ? $booking->occupied_until->hour * 60 + $booking->occupied_until->minute : 24 * 60);
        }
        $start = intdiv($open, 60) * 60;
        $end = (int) ceil($close / 60) * 60;

        $timeOffs = StaffTimeOff::whereIn('staff_id', $staffList->modelKeys())
            ->where('start_at', '<', $day->copy()->endOfDay())
            ->where('end_at', '>', $day)
            ->get()
            ->groupBy('staff_id');

        $columns = $staffList->map(fn (Staff $staff) => [
            'staff' => $staff,
            'bookings' => $bookings->where('staff_id', $staff->id)->map(fn (Booking $b) => [
                'booking' => $b,
                'top' => $this->offset($b->start_at, $day, $start),
                'height' => max(18, ($b->end_at->diffInMinutes($b->start_at, true)) * self::PX_PER_MINUTE - 2),
                'url' => BookingResource::getUrl('view', ['record' => $b]),
            ])->values(),
            'off' => $this->offRanges($staff, $timeOffs->get($staff->id, collect()), $day, $start, $end),
            'count' => $bookings->where('staff_id', $staff->id)->whereIn('status', BookingStatus::blocking())->count(),
        ])
            // Ẩn thợ nghỉ cả ngày và không có lịch
            ->filter(fn (array $column) => $column['staff']->schedules->isNotEmpty() || $column['bookings']->isNotEmpty())
            ->values();

        return [
            'day' => $day,
            'closedDay' => $closedDay,
            'isShopClosed' => $businessHour?->is_closed ?? false,
            'hours' => range($start / 60, $end / 60 - 1),
            'gridHeight' => ($end - $start) * self::PX_PER_MINUTE,
            'hourHeight' => 60 * self::PX_PER_MINUTE,
            'columns' => $columns,
            'nowTop' => $day->isToday() && now()->hour * 60 + now()->minute < $end ? $this->offset(now(), $day, $start) : null,
            'pendingCount' => $bookings->where('status', BookingStatus::Pending)->count(),
            'total' => $bookings->whereIn('status', BookingStatus::blocking())->count(),
        ];
    }

    /**
     * Các khoảng thợ không làm (ngoài ca, lịch nghỉ) để tô xám.
     *
     * @return list<array{top: float, height: float, label: ?string}>
     */
    private function offRanges(Staff $staff, $timeOffs, Carbon $day, int $start, int $end): array
    {
        $working = $staff->schedules
            ->map(fn ($shift) => [$this->minutes($shift->start_time), $this->minutes($shift->end_time)])
            ->sortBy(0)
            ->values();

        $ranges = [];
        $cursor = $start;
        foreach ($working as [$from, $to]) {
            if ($from > $cursor) {
                $ranges[] = [$cursor, $from, null];
            }
            $cursor = max($cursor, $to);
        }
        if ($cursor < $end) {
            $ranges[] = [$cursor, $end, $working->isEmpty() ? 'Nghỉ' : null];
        }

        foreach ($timeOffs as $off) {
            $from = $off->start_at->lt($day) ? $start : $off->start_at->hour * 60 + $off->start_at->minute;
            $to = $off->end_at->gt($day->copy()->endOfDay()) ? $end : $off->end_at->hour * 60 + $off->end_at->minute;
            $ranges[] = [max($from, $start), min($to, $end), $off->reason ?: 'Nghỉ'];
        }

        return array_map(fn (array $r) => [
            'top' => ($r[0] - $start) * self::PX_PER_MINUTE,
            'height' => ($r[1] - $r[0]) * self::PX_PER_MINUTE,
            'label' => $r[2],
        ], array_filter($ranges, fn (array $r) => $r[1] > $r[0]));
    }

    private function offset(Carbon $time, Carbon $day, int $start): float
    {
        return ($time->hour * 60 + $time->minute - $start) * self::PX_PER_MINUTE;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = explode(':', $time);

        return (int) $h * 60 + (int) $m;
    }

    private function day(): Carbon
    {
        return Carbon::parse($this->date ?: today())->startOfDay();
    }
}
