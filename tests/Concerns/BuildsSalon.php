<?php

namespace Tests\Concerns;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BusinessHour;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Staff;
use App\Services\Booking\BookingData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Dựng một tiệm mẫu cho test:
 * - Mở cửa 08:30–20:30 mỗi ngày, "hôm nay" là thứ Hai 05/10/2026 07:00
 * - Cắt tóc 30' (+5' dọn) 100k, Nhuộm 90' (+10') 450k, Sơn gel 45' (+10') 150k
 * - Tuấn: cắt + nhuộm, 09:00–18:00 | Nam: chỉ cắt, ca gãy 09:00–12:00 & 13:30–20:30 | Trang: nail, 09:00–19:00
 */
trait BuildsSalon
{
    protected Service $haircut;

    protected Service $coloring;

    protected Service $gelNails;

    protected Staff $tuan;

    protected Staff $nam;

    protected Staff $trang;

    protected function buildSalon(bool $freezeTime = true): void
    {
        if ($freezeTime) {
            $this->travelTo(Carbon::parse('2026-10-05 07:00'));
        }

        foreach (range(0, 6) as $day) {
            BusinessHour::create(['day_of_week' => $day, 'open_time' => '08:30', 'close_time' => '20:30']);
        }

        $this->haircut = $this->makeService('Cắt tóc', 100_000, 30, 5);
        $this->coloring = $this->makeService('Nhuộm', 450_000, 90, 10);
        $this->gelNails = $this->makeService('Sơn gel', 150_000, 45, 10);

        $this->tuan = $this->makeStaff('Tuấn', [$this->haircut, $this->coloring], [['09:00', '18:00']]);
        $this->nam = $this->makeStaff('Nam', [$this->haircut], [['09:00', '12:00'], ['13:30', '20:30']]);
        $this->trang = $this->makeStaff('Trang', [$this->gelNails], [['09:00', '19:00']]);
    }

    protected function makeService(string $name, int $price, int $duration, int $buffer = 0): Service
    {
        $category = ServiceCategory::firstOrCreate(['slug' => 'dich-vu'], ['name' => 'Dịch vụ']);

        return Service::create([
            'category_id' => $category->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'price' => $price,
            'duration_minutes' => $duration,
            'buffer_minutes' => $buffer,
        ]);
    }

    /**
     * @param  list<Service>  $services
     * @param  list<array{string, string}>  $shifts  ca làm áp cho cả 7 ngày
     */
    protected function makeStaff(string $name, array $services, array $shifts): Staff
    {
        $staff = Staff::create(['name' => $name, 'slug' => Str::slug($name)]);
        $staff->services()->attach(collect($services)->pluck('id'));

        foreach (range(0, 6) as $day) {
            foreach ($shifts as [$start, $end]) {
                $staff->schedules()->create(['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end]);
            }
        }

        return $staff;
    }

    /** Tạo nhanh một booking chiếm chỗ của thợ (không qua BookingService) */
    protected function occupy(Staff $staff, string $start, int $minutes, BookingStatus $status = BookingStatus::Confirmed, int $buffer = 5): Booking
    {
        static $seq = 0;
        $startAt = Carbon::parse($start);
        $customer = Customer::firstOrCreate(['phone' => '0999999999'], ['name' => 'Khách cũ']);

        return Booking::create([
            'code' => 'BKT'.str_pad((string) ++$seq, 5, '0', STR_PAD_LEFT),
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes($minutes),
            'occupied_until' => $startAt->copy()->addMinutes($minutes + $buffer),
            'status' => $status,
        ]);
    }

    /**
     * @param  list<Service>|null  $services
     */
    protected function bookingData(string $start, ?array $services = null, ?Staff $staff = null, array $overrides = []): BookingData
    {
        return new BookingData(...[
            'customerName' => 'Nguyễn Văn A',
            'customerPhone' => '0901234567',
            'serviceIds' => collect($services ?? [$this->haircut])->pluck('id')->all(),
            'startAt' => Carbon::parse($start),
            'staffId' => $staff?->id,
            'source' => BookingSource::Web,
            ...$overrides,
        ]);
    }
}
