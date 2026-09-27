<?php

namespace Tests\Feature\Site;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Livewire\BookingWizard;
use App\Models\Booking;
use App\Models\QrCode;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class BookingWizardTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        RateLimiter::clear('booking-submit:127.0.0.1');
    }

    /** Đi tới bước điền thông tin: cắt tóc, 05/10 10:00 */
    private function wizardAtDetails(?string $staff = 'any')
    {
        return Livewire::test(BookingWizard::class)
            ->call('toggleService', $this->haircut->id)
            ->call('next')
            ->call('selectDate', '2026-10-05')
            ->call('selectTime', '10:00')
            ->call('next')
            ->call('selectStaff', $staff)
            ->call('next')
            ->assertSet('step', 4)
            ->set('name', 'Lê Thu Hà')
            ->set('phone', '0977 123 456');
    }

    public function test_page_renders_the_wizard(): void
    {
        $this->get(route('booking.create'))->assertOk()->assertSeeLivewire(BookingWizard::class)->assertSee('Cắt tóc');
    }

    public function test_customer_books_through_all_steps(): void
    {
        $component = $this->wizardAtDetails((string) $this->tuan->id)
            ->set('email', 'ha@example.com')
            ->set('note', 'Tóc ngắn')
            ->call('submit')
            ->assertHasNoErrors();

        $booking = Booking::sole();
        $component->assertRedirect($booking->manageUrl());

        $this->assertSame(BookingStatus::Pending, $booking->status);
        $this->assertSame(BookingSource::Web, $booking->source);
        $this->assertTrue($booking->staff->is($this->tuan));
        $this->assertSame('2026-10-05 10:00', $booking->start_at->format('Y-m-d H:i'));
        $this->assertSame(['0977123456', 'ha@example.com'], [$booking->customer->phone, $booking->customer->email]);
    }

    public function test_steps_cannot_be_skipped_without_a_choice(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('next')->assertSet('step', 1)
            ->call('toggleService', $this->haircut->id)
            ->call('next')->assertSet('step', 2)
            ->call('next')->assertSet('step', 2)
            ->call('selectDate', '2026-10-05')
            ->call('next')->assertSet('step', 2);
    }

    public function test_only_free_times_can_be_picked(): void
    {
        $this->occupy($this->tuan, '2026-10-05 10:00', 30);
        $this->occupy($this->nam, '2026-10-05 10:00', 30);

        Livewire::test(BookingWizard::class)
            ->call('toggleService', $this->haircut->id)
            ->call('next')
            ->call('selectDate', '2026-10-05')
            ->assertDontSee('data-time="10:00"', false)
            ->assertSee('data-time="10:45"', false)
            ->call('selectTime', '10:00') // bị bỏ qua: không nằm trong danh sách
            ->assertSet('time', null);
    }

    public function test_staff_step_only_lists_staff_free_at_that_time_with_their_price(): void
    {
        $this->nam->services()->updateExistingPivot($this->haircut->id, ['custom_price' => 80_000]);
        $this->occupy($this->tuan, '2026-10-05 10:00', 30);

        Livewire::test(BookingWizard::class)
            ->call('toggleService', $this->haircut->id)
            ->call('next')
            ->call('selectDate', '2026-10-05')
            ->call('selectTime', '10:00')
            ->call('next')
            ->assertSee(['Bất kỳ ai', 'Nam', '80.000đ'])
            ->assertDontSee('Tuấn')
            ->call('selectStaff', (string) $this->tuan->id) // Tuấn bận: bị bỏ qua
            ->assertSet('staffChoice', 'any');
    }

    public function test_slot_taken_meanwhile_sends_the_customer_back_to_pick_another_time(): void
    {
        $component = $this->wizardAtDetails((string) $this->tuan->id);

        // Người khác vừa đặt mất khung giờ này
        $this->occupy($this->tuan, '2026-10-05 10:00', 30, BookingStatus::Pending);

        $component->call('submit')
            ->assertSet('step', 2)
            ->assertSet('time', null)
            ->assertHasErrors('time');

        $this->assertSame(1, Booking::count());
    }

    public function test_voucher_is_previewed_and_applied(): void
    {
        Voucher::create(['code' => 'GIAM20K', 'name' => 'Giảm', 'type' => VoucherType::Fixed, 'value' => 20_000, 'scope' => VoucherScope::All]);

        $this->wizardAtDetails()
            ->set('voucherCode', 'giam20k')
            ->call('applyVoucher')
            ->assertSet('voucher', ['code' => 'GIAM20K', 'discount' => 20_000])
            ->assertSee('80.000đ')
            ->call('submit');

        $this->assertSame(80_000, Booking::sole()->total);
    }

    public function test_invalid_voucher_shows_an_error(): void
    {
        $this->wizardAtDetails()
            ->set('voucherCode', 'KHONGCO')
            ->call('applyVoucher')
            ->assertHasErrors(['voucherCode' => 'Mã giảm giá không tồn tại hoặc đã ngừng áp dụng.'])
            ->assertSet('voucher', null);
    }

    public function test_contact_details_are_validated(): void
    {
        $this->wizardAtDetails()
            ->set('name', '')
            ->set('phone', '123')
            ->set('email', 'not-an-email')
            ->call('submit')
            ->assertHasErrors(['name', 'phone', 'email']);

        $this->assertSame(0, Booking::count());
    }

    public function test_honeypot_blocks_bots(): void
    {
        $this->wizardAtDetails()->set('website', 'http://spam.example')->call('submit')->assertHasErrors('form');

        $this->assertSame(0, Booking::count());
    }

    public function test_submissions_are_rate_limited_per_ip(): void
    {
        foreach (range(1, BookingWizard::MAX_SUBMITS) as $i) {
            RateLimiter::hit('booking-submit:127.0.0.1', 600);
        }

        $this->wizardAtDetails()->call('submit')->assertHasErrors('form');

        $this->assertSame(0, Booking::count());
    }

    public function test_preferred_staff_filters_services_and_times(): void
    {
        $this->occupy($this->tuan, '2026-10-05 09:00', 30);

        Livewire::test(BookingWizard::class, ['staff' => $this->tuan->id])
            ->assertSee('Đặt với')
            ->assertDontSee('Sơn gel') // Tuấn không làm nail
            ->call('toggleService', $this->gelNails->id)
            ->assertSet('serviceIds', [])
            ->call('toggleService', $this->haircut->id)
            ->call('next')
            ->call('selectDate', '2026-10-05')
            ->assertDontSee('data-time="09:00"', false) // Tuấn bận, dù Nam rảnh
            ->call('selectTime', '10:00')
            ->assertSet('staffChoice', (string) $this->tuan->id)
            ->call('clearPreferredStaff')
            ->assertSet('preferredStaffId', null)
            ->assertSet('date', null);
    }

    public function test_booking_from_a_qr_code_is_attributed_to_it(): void
    {
        $qr = QrCode::create(['code' => 'QUAY', 'name' => 'Quầy']);
        session(['booking.qr_code_id' => $qr->id]);

        $this->wizardAtDetails()->call('submit');

        $booking = Booking::sole();
        $this->assertSame(BookingSource::Qr, $booking->source);
        $this->assertTrue($booking->qrCode->is($qr));
        $this->assertNull(session('booking.qr_code_id'));
    }
}
