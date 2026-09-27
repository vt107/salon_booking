<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Models\ClosedDay;
use App\Models\Setting;
use App\Models\StaffTimeOff;
use App\Services\Booking\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AvailabilityServiceTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private AvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->availability = app(AvailabilityService::class);
    }

    private function times(string $date, array $services, ?int $staffId = null): array
    {
        return array_keys($this->availability->slotsForDate(Carbon::parse($date), collect($services)->pluck('id')->all(), $staffId));
    }

    public function test_slots_follow_shift_and_slot_grid(): void
    {
        $times = $this->times('2026-10-05', [$this->haircut], $this->tuan->id);

        // Cắt 30' + dọn 5' phải xong trước 18:00 => khung cuối 17:15
        $this->assertSame('09:00', $times[0]);
        $this->assertSame('17:15', end($times));
        $this->assertCount(34, $times);
    }

    public function test_shift_is_clipped_by_business_hours(): void
    {
        $early = $this->makeStaff('Sớm', [$this->haircut], [['07:00', '22:00']]);

        $times = $this->times('2026-10-05', [$this->haircut], $early->id);

        $this->assertSame('08:30', $times[0]);
        $this->assertSame('19:45', end($times));
    }

    public function test_existing_booking_and_its_buffer_are_excluded(): void
    {
        $this->occupy($this->tuan, '2026-10-05 10:00', 30); // chiếm 10:00–10:35

        $times = $this->times('2026-10-05', [$this->haircut], $this->tuan->id);

        $this->assertContains('09:15', $times);    // 09:15–09:50 vẫn kịp
        $this->assertNotContains('09:30', $times); // 09:30–10:05 đụng booking
        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('10:30', $times);
        $this->assertContains('10:45', $times);    // làm tròn lên từ 10:35
    }

    public function test_pending_booking_holds_the_slot_but_cancelled_does_not(): void
    {
        $this->occupy($this->tuan, '2026-10-05 10:00', 30, BookingStatus::Pending);
        $this->occupy($this->tuan, '2026-10-05 14:00', 30, BookingStatus::Cancelled);
        $this->occupy($this->tuan, '2026-10-05 15:00', 30, BookingStatus::Rejected);

        $times = $this->times('2026-10-05', [$this->haircut], $this->tuan->id);

        $this->assertNotContains('10:00', $times);
        $this->assertContains('14:00', $times);
        $this->assertContains('15:00', $times);
    }

    public function test_time_off_is_excluded(): void
    {
        StaffTimeOff::create(['staff_id' => $this->tuan->id, 'start_at' => '2026-10-05 13:00', 'end_at' => '2026-10-05 15:00']);

        $times = $this->times('2026-10-05', [$this->haircut], $this->tuan->id);

        $this->assertContains('12:15', $times);
        $this->assertNotContains('12:30', $times);
        $this->assertNotContains('14:45', $times);
        $this->assertContains('15:00', $times);
    }

    public function test_closed_day_has_no_slots_and_is_not_an_available_date(): void
    {
        ClosedDay::create(['date' => '2026-10-06', 'reason' => 'Nghỉ lễ']);

        $this->assertSame([], $this->times('2026-10-06', [$this->haircut]));

        $dates = $this->availability->availableDates([$this->haircut->id]);
        $this->assertContains('2026-10-05', $dates);
        $this->assertNotContains('2026-10-06', $dates);
        $this->assertContains('2026-10-07', $dates);
    }

    public function test_minimum_lead_time_applies_to_today(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:07'));

        $times = $this->times('2026-10-05', [$this->haircut], $this->tuan->id);

        $this->assertSame('11:15', $times[0]); // 10:07 + 60' = 11:07 => làm tròn lên 11:15
    }

    public function test_past_dates_have_no_slots(): void
    {
        $this->assertSame([], $this->times('2026-10-04', [$this->haircut]));
    }

    public function test_split_shift_leaves_the_break_free(): void
    {
        $times = $this->times('2026-10-05', [$this->haircut], $this->nam->id);

        $this->assertContains('11:15', $times);    // 11:15–11:50 xong trước 12:00
        $this->assertNotContains('11:30', $times);
        $this->assertNotContains('13:00', $times);
        $this->assertContains('13:30', $times);
    }

    public function test_staff_specific_duration_is_used(): void
    {
        $this->tuan->services()->updateExistingPivot($this->haircut->id, ['custom_duration_minutes' => 60]);

        $times = $this->times('2026-10-05', [$this->haircut], $this->tuan->id);

        $this->assertSame('16:45', end($times)); // 60' + 5' phải xong trước 18:00
    }

    public function test_multiple_services_are_done_back_to_back(): void
    {
        // Cắt 30' + nhuộm 90' + dọn 10' (buffer của dịch vụ cuối) = 130'
        $times = $this->times('2026-10-05', [$this->haircut, $this->coloring], $this->tuan->id);

        $this->assertSame('15:45', end($times));
    }

    public function test_any_staff_returns_union_of_staff_who_can_do_all_services(): void
    {
        $slots = $this->availability->slotsForDate(Carbon::parse('2026-10-05'), [$this->haircut->id]);

        $this->assertEqualsCanonicalizing([$this->tuan->id, $this->nam->id], $slots['09:00']);
        $this->assertSame([$this->tuan->id], $slots['12:30']); // Nam nghỉ trưa
        $this->assertSame([$this->nam->id], $slots['19:00']); // Tuấn đã hết ca

        // Chỉ Tuấn làm được cả cắt lẫn nhuộm
        $both = $this->availability->slotsForDate(Carbon::parse('2026-10-05'), [$this->haircut->id, $this->coloring->id]);
        $this->assertSame([$this->tuan->id], $both['09:00']);
    }

    public function test_inactive_or_unbookable_staff_are_ignored(): void
    {
        $this->nam->update(['is_bookable' => false]);

        $slots = $this->availability->slotsForDate(Carbon::parse('2026-10-05'), [$this->haircut->id]);

        $this->assertSame([$this->tuan->id], $slots['09:00']);
        $this->assertArrayNotHasKey('19:00', $slots);
    }

    public function test_staff_available_at_a_given_time(): void
    {
        $this->occupy($this->tuan, '2026-10-05 09:00', 30);

        $staff = $this->availability->staffAvailableAt(Carbon::parse('2026-10-05 09:00'), [$this->haircut->id]);

        $this->assertSame([$this->nam->id], $staff->modelKeys());
    }

    public function test_available_dates_respect_max_advance_days(): void
    {
        Setting::set('booking.max_advance_days', 3);

        $this->assertSame(
            ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'],
            $this->availability->availableDates([$this->haircut->id]),
        );
    }

    public function test_fits_working_hours(): void
    {
        $fits = fn (string $start, string $end) => $this->availability->fitsWorkingHours($this->nam, Carbon::parse($start), Carbon::parse($end));

        $this->assertTrue($fits('2026-10-05 09:00', '2026-10-05 09:35'));
        $this->assertFalse($fits('2026-10-05 11:45', '2026-10-05 12:20')); // lấn giờ nghỉ trưa
        $this->assertFalse($fits('2026-10-05 20:15', '2026-10-05 20:50')); // quá giờ đóng cửa
    }

    public function test_next_opening_moment(): void
    {
        $at = fn (string $time) => $this->availability->nextOpeningMoment(Carbon::parse($time))->format('Y-m-d H:i');

        $this->assertSame('2026-10-05 08:30', $at('2026-10-05 07:00'));
        $this->assertSame('2026-10-05 10:00', $at('2026-10-05 10:00'));
        $this->assertSame('2026-10-06 08:30', $at('2026-10-05 23:00'));
    }
}
