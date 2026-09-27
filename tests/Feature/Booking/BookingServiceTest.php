<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Events\BookingCreated;
use App\Events\BookingRescheduled;
use App\Events\BookingStatusChanged;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class BookingServiceTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private BookingService $bookings;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->bookings = app(BookingService::class);
        $this->admin = User::factory()->admin()->create();
    }

    private function assertBookingFails(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected BookingException');
        } catch (BookingException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_customer_booking_is_pending_with_snapshot_items_and_history(): void
    {
        Event::fake([BookingCreated::class]);

        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00', [$this->haircut, $this->coloring], $this->tuan, [
            'customerNote' => 'Nhuộm màu nâu',
        ]));

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertMatchesRegularExpression('/^BK[2-9A-HJ-NP-Z]{6}$/', $booking->code);
        $this->assertSame('2026-10-05 12:00', $booking->end_at->format('Y-m-d H:i'));
        $this->assertSame('2026-10-05 12:10', $booking->occupied_until->format('Y-m-d H:i'));
        $this->assertSame(550_000, $booking->total);
        $this->assertFalse($booking->is_staff_auto_assigned);
        // Đặt lúc 07:00 => hạn duyệt tính từ 08:30 (mở cửa) + 60' = 09:30
        $this->assertSame('2026-10-05 09:30', $booking->approval_deadline_at->format('Y-m-d H:i'));

        $items = $booking->items;
        $this->assertSame(['Cắt tóc', 'Nhuộm'], $items->pluck('service_name')->all());
        $this->assertSame(['10:00', '10:30'], $items->map(fn ($i) => $i->start_at->format('H:i'))->all());

        $history = $booking->statusHistories()->sole();
        $this->assertNull($history->from_status);
        $this->assertSame(BookingStatus::Pending, $history->to_status);
        $this->assertTrue($history->actor->is($booking->customer));

        Event::assertDispatched(BookingCreated::class, fn ($e) => $e->booking->is($booking));
    }

    public function test_approval_deadline_never_passes_shortly_before_the_appointment(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 10:00'));

        $booking = $this->bookings->create($this->bookingData('2026-10-05 11:15'));

        // min(10:00 + 60', 11:15 - 30') = 10:45
        $this->assertSame('10:45', $booking->approval_deadline_at->format('H:i'));
    }

    public function test_existing_customer_is_found_by_normalized_phone(): void
    {
        $customer = Customer::create(['name' => 'Chị Lan', 'phone' => '0901234567']);

        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00', overrides: [
            'customerPhone' => '+84 90 123 4567',
            'customerEmail' => 'lan@example.com',
        ]));

        $this->assertTrue($booking->customer->is($customer));
        $this->assertSame('Chị Lan', $customer->fresh()->name);
        $this->assertSame('lan@example.com', $customer->fresh()->email);
        $this->assertSame(1, Customer::count());
    }

    public function test_staff_specific_price_is_snapshotted(): void
    {
        $this->tuan->services()->updateExistingPivot($this->haircut->id, ['custom_price' => 150_000]);

        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan));
        $this->haircut->update(['price' => 999_000]);

        $this->assertSame(150_000, $booking->fresh()->items->first()->price);
        $this->assertSame(150_000, $booking->fresh()->total);
    }

    public function test_any_staff_assigns_the_least_busy_available_staff(): void
    {
        $this->occupy($this->tuan, '2026-10-05 15:00', 30);

        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00'));

        $this->assertTrue($booking->staff->is($this->nam));
        $this->assertTrue($booking->is_staff_auto_assigned);
    }

    public function test_any_staff_falls_back_to_whoever_is_free(): void
    {
        $this->occupy($this->nam, '2026-10-05 10:00', 30);

        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00'));

        $this->assertTrue($booking->staff->is($this->tuan));
    }

    public function test_taken_slot_is_rejected(): void
    {
        $this->occupy($this->tuan, '2026-10-05 10:00', 30, BookingStatus::Pending);

        $this->assertBookingFails('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 10:15', staff: $this->tuan)));

        // Buffer 5' của booking trước: 10:30 vẫn bị chiếm tới 10:35
        $this->assertBookingFails('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 10:30', staff: $this->tuan)));

        $this->assertSame(BookingStatus::Pending, $this->bookings->create($this->bookingData('2026-10-05 10:35', staff: $this->tuan))->status);
    }

    public function test_second_customer_cannot_take_the_same_slot(): void
    {
        $this->bookings->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan));

        $this->assertBookingFails('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan, overrides: ['customerPhone' => '0987654321'])));
    }

    public function test_booking_outside_working_hours_is_rejected_unless_staff_overrides(): void
    {
        $this->assertBookingFails('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 12:00', staff: $this->nam)));

        $booking = $this->bookings->create($this->bookingData('2026-10-05 12:00', staff: $this->nam, overrides: [
            'createdBy' => $this->admin,
            'source' => BookingSource::Phone,
            'allowOutsideWorkingHours' => true,
        ]));

        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertNull($booking->approval_deadline_at);
        $this->assertTrue($booking->confirmedBy->is($this->admin));
        $this->assertTrue($booking->statusHistories()->sole()->actor->is($this->admin));
    }

    public function test_booking_window_and_input_validation(): void
    {
        $this->assertBookingFails('Vui lòng đặt lịch trước ít nhất 60 phút.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 07:30')));
        $this->assertBookingFails('Chỉ nhận đặt lịch trong vòng 30 ngày tới.',
            fn () => $this->bookings->create($this->bookingData('2026-11-05 10:00')));
        $this->assertBookingFails('Số điện thoại không hợp lệ.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 10:00', overrides: ['customerPhone' => '12345'])));
        $this->assertBookingFails('Vui lòng chọn ít nhất một dịch vụ.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 10:00', [])));
        $this->assertBookingFails('Nhân viên đã chọn không làm được tất cả dịch vụ này.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 10:00', [$this->coloring], $this->nam)));
    }

    public function test_blocked_customer_and_pending_limit(): void
    {
        $this->bookings->create($this->bookingData('2026-10-05 10:00'));
        $this->bookings->create($this->bookingData('2026-10-05 11:00'));

        $this->assertBookingFails('Bạn đang có 2 lịch chờ xác nhận. Vui lòng đợi tiệm xác nhận trước khi đặt thêm.',
            fn () => $this->bookings->create($this->bookingData('2026-10-05 12:00')));

        Customer::where('phone', '0901234567')->update(['is_blocked' => true]);

        $this->assertBookingFails('Không thể đặt lịch online, vui lòng liên hệ trực tiếp với tiệm.',
            fn () => $this->bookings->create($this->bookingData('2026-10-06 10:00')));
    }

    public function test_voucher_is_applied_and_released_when_rejected(): void
    {
        $voucher = Voucher::create(['code' => 'GIAM50K', 'name' => 'Giảm 50k', 'type' => VoucherType::Fixed, 'value' => 50_000, 'scope' => VoucherScope::All]);

        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00', [$this->haircut, $this->coloring], $this->tuan, [
            'voucherCode' => 'giam50k',
        ]));

        $this->assertSame(50_000, $booking->discount_amount);
        $this->assertSame(500_000, $booking->total);
        $this->assertSame(50_000, $booking->items->sum('discount_amount'));
        $this->assertSame(1, $voucher->fresh()->used_count);

        $this->bookings->reject($booking, $this->admin, 'Thợ bận');

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertNotNull($booking->voucherUsage()->first()->released_at);
    }

    public function test_confirm_and_reject_only_from_pending(): void
    {
        Event::fake([BookingStatusChanged::class]);
        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00'));

        $confirmed = $this->bookings->confirm($booking, $this->admin);

        $this->assertSame(BookingStatus::Confirmed, $confirmed->status);
        $this->assertNull($confirmed->approval_deadline_at);
        $this->assertTrue($confirmed->confirmedBy->is($this->admin));
        Event::assertDispatched(BookingStatusChanged::class, fn ($e) => $e->from === BookingStatus::Pending && $e->to === BookingStatus::Confirmed);

        // Hai admin bấm cùng lúc: người thứ hai nhận lỗi, không ghi đè
        $this->assertBookingFails('Không thể chuyển lịch từ "Đã xác nhận" sang "Đã xác nhận".', fn () => $this->bookings->confirm($booking, $this->admin));
        $this->assertBookingFails('Không thể chuyển lịch từ "Đã xác nhận" sang "Từ chối".', fn () => $this->bookings->reject($booking, $this->admin));
        $this->assertSame(2, $booking->statusHistories()->count());
    }

    public function test_customer_cannot_cancel_a_confirmed_booking_too_close_to_the_appointment(): void
    {
        $booking = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-05 10:00')), $this->admin);
        $this->travelTo(Carbon::parse('2026-10-05 08:30'));

        $this->assertBookingFails('Chỉ có thể hủy lịch trước giờ hẹn ít nhất 2 giờ. Vui lòng liên hệ tiệm.',
            fn () => $this->bookings->cancel($booking, CancelledBy::Customer, $booking->customer));

        $cancelled = $this->bookings->cancel($booking, CancelledBy::Staff, $this->admin, 'Khách gọi báo bận');

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertSame(CancelledBy::Staff, $cancelled->cancelled_by);
        $this->assertSame('Khách gọi báo bận', $cancelled->status_reason);
    }

    public function test_customer_can_cancel_a_pending_booking_anytime(): void
    {
        $booking = $this->bookings->create($this->bookingData('2026-10-05 09:00'));
        $this->travelTo(Carbon::parse('2026-10-05 08:00'));

        $cancelled = $this->bookings->cancel($booking, CancelledBy::Customer, $booking->customer);

        $this->assertSame(BookingStatus::Cancelled, $cancelled->status);
        $this->assertTrue($cancelled->statusHistories()->first()->actor->is($booking->customer));
    }

    public function test_complete_checks_in_records_payment_and_customer_stats(): void
    {
        $booking = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-05 10:00')), $this->admin);
        $this->travelTo(Carbon::parse('2026-10-05 10:40'));

        $completed = $this->bookings->complete($booking, $this->admin, PaymentMethod::BankTransfer);

        $this->assertSame(BookingStatus::Completed, $completed->status);
        $this->assertSame(PaymentMethod::BankTransfer, $completed->payment_method);
        $this->assertNotNull($completed->checked_in_at);
        $this->assertSame('2026-10-05 10:40', $completed->paid_at->format('Y-m-d H:i'));
        $this->assertSame(
            ['pending', 'confirmed', 'in_progress', 'completed'],
            $completed->statusHistories()->reorder('id')->pluck('to_status')->map->value->all(),
        );

        $customer = $completed->customer->fresh();
        $this->assertSame(1, $customer->total_visits);
        $this->assertSame(100_000, $customer->total_spent);
        $this->assertSame('2026-10-05 10:00', $customer->last_visit_at->format('Y-m-d H:i'));
    }

    public function test_no_show_is_counted_on_the_customer(): void
    {
        $booking = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-05 10:00')), $this->admin);

        $this->bookings->markNoShow($booking);

        $this->assertSame(BookingStatus::NoShow, $booking->fresh()->status);
        $this->assertSame(1, $booking->customer->fresh()->no_show_count);
        $this->assertNull($booking->statusHistories()->first()->actor_type);
    }

    public function test_reschedule_moves_booking_and_recalculates_duration_for_the_new_staff(): void
    {
        Event::fake([BookingRescheduled::class]);
        $this->nam->services()->updateExistingPivot($this->haircut->id, ['custom_duration_minutes' => 45]);
        $booking = $this->bookings->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan));

        // Dời 15' trên chính thợ đó: phải bỏ qua khoảng đang chiếm của chính booking này
        $booking = $this->bookings->reschedule($booking, Carbon::parse('2026-10-05 10:15'), null, $this->admin);
        $this->assertSame('10:15', $booking->start_at->format('H:i'));

        $booking = $this->bookings->reschedule($booking, Carbon::parse('2026-10-05 14:00'), $this->nam->id, $this->admin);

        $this->assertTrue($booking->staff->is($this->nam));
        $this->assertSame('14:45', $booking->end_at->format('H:i'));
        $this->assertSame('14:50', $booking->occupied_until->format('H:i'));
        $this->assertSame(45, $booking->items->first()->fresh()->duration_minutes);
        $this->assertSame(100_000, $booking->total);
        Event::assertDispatchedTimes(BookingRescheduled::class, 2);

        $this->occupy($this->tuan, '2026-10-05 16:00', 30);
        $this->assertBookingFails('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.',
            fn () => $this->bookings->reschedule($booking, Carbon::parse('2026-10-05 16:00'), $this->tuan->id, $this->admin));
    }

    public function test_expire_cancels_only_overdue_pending_bookings(): void
    {
        $overdue = $this->bookings->create($this->bookingData('2026-10-05 12:00'));
        $confirmed = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-05 13:00')), $this->admin);
        $this->travelTo(Carbon::parse('2026-10-05 09:31'));
        $fresh = $this->bookings->create($this->bookingData('2026-10-05 15:00', overrides: ['customerPhone' => '0987654321']));

        $this->artisan('bookings:expire-pending')->assertSuccessful();

        $this->assertSame(BookingStatus::Cancelled, $overdue->fresh()->status);
        $this->assertSame(CancelledBy::System, $overdue->fresh()->cancelled_by);
        $this->assertSame(BookingStatus::Confirmed, $confirmed->fresh()->status);
        $this->assertSame(BookingStatus::Pending, $fresh->fresh()->status);
    }

    public function test_mark_no_show_command_uses_grace_period(): void
    {
        $late = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-05 10:00')), $this->admin);
        $soon = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-05 10:30', staff: $this->nam)), $this->admin);
        $this->travelTo(Carbon::parse('2026-10-05 10:45'));

        $this->artisan('bookings:mark-no-show')->assertSuccessful();

        $this->assertSame(BookingStatus::NoShow, $late->fresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $soon->fresh()->status);
    }

    public function test_staff_role_cannot_approve_but_manager_can(): void
    {
        $this->assertFalse(User::factory()->create(['role' => UserRole::Staff])->canApproveBookings());
        $this->assertTrue(User::factory()->create(['role' => UserRole::Manager])->canApproveBookings());
        $this->assertFalse(User::factory()->create(['role' => UserRole::Manager, 'is_active' => false])->canApproveBookings());
    }
}
