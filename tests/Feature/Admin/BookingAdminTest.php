<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Filament\Resources\Bookings\BookingResource;
use App\Filament\Resources\Bookings\Pages\CreateBooking;
use App\Filament\Resources\Bookings\Pages\ListBookings;
use App\Filament\Resources\Bookings\Pages\ViewBooking;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\BookingsRelationManager;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class BookingAdminTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $admin;

    private Booking $pending;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->admin = User::factory()->admin()->create();
        $this->pending = app(BookingService::class)->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan));
        $this->actingAs($this->admin);
    }

    private function confirmed(): Booking
    {
        return app(BookingService::class)->confirm($this->pending, $this->admin);
    }

    public function test_list_opens_on_pending_tab_and_shows_navigation_badge(): void
    {
        Livewire::test(ListBookings::class)
            ->assertSet('activeTab', 'pending')
            ->assertCanSeeTableRecords([$this->pending]);

        $this->assertSame('1', BookingResource::getNavigationBadge());
    }

    public function test_admin_confirms_from_the_table(): void
    {
        Livewire::test(ListBookings::class)
            ->callTableAction('confirm', $this->pending)
            ->assertNotified('Đã xác nhận lịch hẹn');

        $this->assertSame(BookingStatus::Confirmed, $this->pending->fresh()->status);
        $this->assertTrue($this->pending->fresh()->confirmedBy->is($this->admin));
    }

    public function test_staff_role_cannot_approve_but_can_see_the_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Staff]));

        Livewire::test(ListBookings::class)
            ->assertCanSeeTableRecords([$this->pending])
            ->assertTableActionHidden('confirm', $this->pending)
            ->assertTableActionHidden('reject', $this->pending);
    }

    public function test_reject_combines_preset_and_detail_into_the_reason(): void
    {
        Livewire::test(ListBookings::class)
            ->callTableAction('reject', $this->pending, ['reason' => 'Thợ bận đột xuất', 'detail' => 'Anh Tuấn nghỉ ốm']);

        $this->assertSame(BookingStatus::Rejected, $this->pending->fresh()->status);
        $this->assertSame('Thợ bận đột xuất: Anh Tuấn nghỉ ốm', $this->pending->fresh()->status_reason);
    }

    public function test_staff_can_record_a_phone_cancellation_close_to_the_appointment(): void
    {
        $booking = $this->confirmed();
        $this->travelTo(Carbon::parse('2026-10-05 09:30'));

        Livewire::test(ViewBooking::class, ['record' => $booking->id])
            ->callAction('cancel', ['cancelled_by' => CancelledBy::Customer->value, 'reason' => 'Gọi điện báo bận']);

        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()->status);
        $this->assertSame(CancelledBy::Customer, $booking->fresh()->cancelled_by);
    }

    public function test_complete_from_view_page_records_payment(): void
    {
        $booking = $this->confirmed();
        $this->travelTo(Carbon::parse('2026-10-05 10:35'));

        Livewire::test(ViewBooking::class, ['record' => $booking->id])
            ->assertSee($booking->code)
            ->callAction('complete', ['payment_method' => PaymentMethod::BankTransfer->value])
            ->assertActionHidden('complete');

        $this->assertSame(BookingStatus::Completed, $booking->fresh()->status);
        $this->assertSame(PaymentMethod::BankTransfer, $booking->fresh()->payment_method);
    }

    public function test_reschedule_to_another_staff(): void
    {
        Livewire::test(ListBookings::class)
            ->callTableAction('reschedule', $this->pending, [
                'staff_id' => $this->nam->id,
                'date' => '2026-10-05',
                'outside_hours' => false,
                'time' => '14:00',
            ])
            ->assertNotified('Đã đổi lịch hẹn');

        $this->assertTrue($this->pending->fresh()->staff->is($this->nam));
        $this->assertSame('14:00', $this->pending->fresh()->start_at->format('H:i'));
    }

    public function test_business_errors_are_shown_as_notifications(): void
    {
        Livewire::test(ListBookings::class)
            ->callTableAction('reschedule', $this->pending, [
                'staff_id' => $this->nam->id,
                'date' => '2026-10-05',
                'outside_hours' => true,
                'manual_time' => '12:00',
            ]);

        // Ngoài giờ làm được phép, nhưng 12:00 là giờ nghỉ trưa của Nam và vẫn không trùng ai => thành công
        $this->assertSame('12:00', $this->pending->fresh()->start_at->format('H:i'));

        $this->occupy($this->tuan, '2026-10-05 15:00', 30);

        Livewire::test(ListBookings::class)
            ->callTableAction('reschedule', $this->pending, [
                'staff_id' => $this->tuan->id,
                'date' => '2026-10-05',
                'outside_hours' => true,
                'manual_time' => '15:00',
            ])
            ->assertNotified('Khung giờ này vừa có người đặt, vui lòng chọn giờ khác.');

        $this->assertSame('12:00', $this->pending->fresh()->start_at->format('H:i'));
    }

    public function test_front_desk_creates_a_confirmed_booking_for_a_walk_in_customer(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 13:52'));

        Livewire::test(CreateBooking::class)
            ->fillForm([
                'customer_phone' => '0912 345 678',
                'customer_name' => 'Chị Mai',
                'source' => BookingSource::WalkIn->value,
                'service_ids' => [(string) $this->haircut->id],
                'staff_id' => null,
                'date' => '2026-10-05',
                'outside_hours' => false,
                'time' => '14:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $booking = Booking::where('source', BookingSource::WalkIn)->sole();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame('0912345678', $booking->customer->phone);
        $this->assertTrue($booking->creator->is($this->admin));
    }

    public function test_create_form_only_offers_free_slots(): void
    {
        Livewire::test(CreateBooking::class)
            ->fillForm([
                'customer_phone' => '0912345678',
                'customer_name' => 'Chị Mai',
                'service_ids' => [(string) $this->haircut->id],
                'staff_id' => (string) $this->tuan->id,
                'date' => '2026-10-05',
                'time' => '10:00', // đang bị booking pending giữ chỗ
            ])
            ->call('create')
            ->assertHasFormErrors(['time']);

        $this->assertSame(1, Booking::count());
    }

    public function test_customer_page_shows_booking_history(): void
    {
        $customer = $this->pending->customer;

        $this->get(CustomerResource::getUrl('view', ['record' => $customer]))->assertOk()->assertSee($customer->name);

        Livewire::test(BookingsRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
            ->assertCanSeeTableRecords([$this->pending]);
    }

    public function test_booking_view_page_renders(): void
    {
        $this->get(BookingResource::getUrl('view', ['record' => $this->pending]))
            ->assertOk()
            ->assertSee($this->pending->code)
            ->assertSee('Chờ duyệt');
    }
}
