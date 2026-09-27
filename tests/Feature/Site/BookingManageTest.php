<?php

namespace Tests\Feature\Site;

use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class BookingManageTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private Booking $booking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->booking = app(BookingService::class)->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan));
    }

    private function cancelUrl(): string
    {
        return URL::signedRoute('booking.cancel', ['booking' => $this->booking->code]);
    }

    public function test_signed_link_shows_the_booking(): void
    {
        $this->get($this->booking->manageUrl())
            ->assertOk()
            ->assertSee([$this->booking->code, 'Chờ duyệt', 'Tuấn', 'Cắt tóc', '100.000đ', 'Hủy lịch hẹn']);
    }

    public function test_link_without_a_valid_signature_is_rejected(): void
    {
        $this->get(route('booking.show', ['booking' => $this->booking->code]))->assertForbidden();
        $this->get($this->booking->manageUrl().'x')->assertForbidden();
        $this->post(route('booking.cancel', ['booking' => $this->booking->code]))->assertForbidden();
    }

    public function test_customer_cancels_a_pending_booking(): void
    {
        $this->post($this->cancelUrl(), ['reason' => 'Bận việc'])
            ->assertRedirect($this->booking->manageUrl())
            ->assertSessionHas('success');

        $booking = $this->booking->fresh();
        $this->assertSame(BookingStatus::Cancelled, $booking->status);
        $this->assertSame(CancelledBy::Customer, $booking->cancelled_by);
        $this->assertSame('Bận việc', $booking->status_reason);
    }

    public function test_confirmed_booking_cannot_be_cancelled_online_too_close_to_the_appointment(): void
    {
        app(BookingService::class)->confirm($this->booking, User::factory()->admin()->create());
        $this->travelTo(Carbon::parse('2026-10-05 08:30'));

        $this->get($this->booking->manageUrl())
            ->assertSee('Đã quá thời hạn hủy online')
            ->assertDontSee('Xác nhận hủy lịch');

        $this->post($this->cancelUrl())->assertSessionHas('error');
        $this->assertSame(BookingStatus::Confirmed, $this->booking->fresh()->status);
    }
}
