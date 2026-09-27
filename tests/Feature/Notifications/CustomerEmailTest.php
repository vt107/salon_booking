<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingSource;
use App\Enums\CancelledBy;
use App\Models\Booking;
use App\Models\NotificationLog;
use App\Models\User;
use App\Notifications\Customer\BookingCancelledMail;
use App\Notifications\Customer\BookingConfirmedMail;
use App\Notifications\Customer\BookingReceivedMail;
use App\Notifications\Customer\BookingRejectedMail;
use App\Notifications\Customer\BookingReminderMail;
use App\Notifications\Customer\BookingRescheduledMail;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class CustomerEmailTest extends TestCase
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

    private function book(array $overrides = []): Booking
    {
        return $this->bookings->create($this->bookingData('2026-10-05 10:00', staff: $this->tuan, overrides: ['customerEmail' => 'ha@example.com', ...$overrides]));
    }

    public function test_customer_gets_a_receipt_then_a_confirmation(): void
    {
        Notification::fake();

        $booking = $this->book();
        Notification::assertSentTo($booking->customer, BookingReceivedMail::class);

        $this->bookings->confirm($booking, $this->admin);
        Notification::assertSentTo($booking->customer, BookingConfirmedMail::class);
    }

    public function test_booking_created_by_staff_is_confirmed_straight_away(): void
    {
        Notification::fake();

        $booking = $this->book(['createdBy' => $this->admin, 'source' => BookingSource::Phone]);

        Notification::assertSentTo($booking->customer, BookingConfirmedMail::class);
        Notification::assertNotSentTo($booking->customer, BookingReceivedMail::class);
    }

    public function test_rejection_cancellation_and_reschedule_emails(): void
    {
        Notification::fake();

        $rejected = $this->book();
        $this->bookings->reject($rejected, $this->admin, 'Thợ bận đột xuất');
        Notification::assertSentTo($rejected->customer, BookingRejectedMail::class);

        $cancelled = $this->book(['customerPhone' => '0911111111']);
        $this->bookings->cancel($cancelled, CancelledBy::Staff, $this->admin, 'Mất điện');
        Notification::assertSentTo($cancelled->customer, BookingCancelledMail::class);

        $moved = $this->book(['customerPhone' => '0922222222']);
        $this->bookings->reschedule($moved, Carbon::parse('2026-10-05 15:00'), null, $this->admin);
        Notification::assertSentTo($moved->customer, BookingRescheduledMail::class,
            fn (BookingRescheduledMail $mail) => $mail->previousStartAt->format('H:i') === '10:00');
    }

    public function test_customers_without_email_are_skipped(): void
    {
        $booking = $this->book(['customerEmail' => null]);

        $this->assertSame([], (new BookingReceivedMail($booking))->via($booking->customer));
    }

    public function test_confirmation_email_content(): void
    {
        $booking = $this->bookings->confirm($this->book(), $this->admin);

        $html = (string) (new BookingConfirmedMail($booking))->toMail($booking->customer)->render();

        $this->assertStringContainsString('Hẹn gặp bạn!', $html);
        $this->assertStringContainsString($booking->code, $html);
        $this->assertStringContainsString('10:00, Thứ Hai 05/10/2026', $html);
        $this->assertStringContainsString('100.000đ', $html);
        $this->assertStringContainsString(e($booking->manageUrl()), $html);
    }

    public function test_expired_booking_email_apologises_and_invites_to_book_again(): void
    {
        $booking = $this->book();
        $this->travelTo(Carbon::parse('2026-10-05 09:31'));
        $this->bookings->expire($booking);

        $mail = (new BookingCancelledMail($booking->fresh()))->toMail($booking->customer);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Tiệm chưa kịp xác nhận lịch của bạn', $html);
        $this->assertStringContainsString(route('booking.create'), $html);
    }

    public function test_reminders_are_sent_once_for_bookings_confirmed_in_advance(): void
    {
        Notification::fake();
        $early = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-06 10:00', staff: $this->tuan, overrides: ['customerEmail' => 'a@example.com'])), $this->admin);

        $this->travelTo(Carbon::parse('2026-10-06 08:30'));
        $late = $this->bookings->confirm($this->bookings->create($this->bookingData('2026-10-06 11:00', staff: $this->nam, overrides: ['customerEmail' => 'b@example.com', 'customerPhone' => '0933333333'])), $this->admin);

        $this->artisan('bookings:send-reminders')->assertSuccessful();
        $this->artisan('bookings:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($early->customer, BookingReminderMail::class, 1);
        Notification::assertNotSentTo($late->customer, BookingReminderMail::class); // vừa xác nhận sát giờ
        $this->assertNotNull($late->fresh()->reminder_sent_at);
    }

    public function test_sent_emails_are_logged(): void
    {
        $booking = $this->book();

        $log = NotificationLog::sole();
        $this->assertSame(['BookingReceivedMail', 'mail', 'ha@example.com', 'sent', $booking->id],
            [$log->type, $log->channel, $log->recipient, $log->status, $log->booking_id]);
    }
}
