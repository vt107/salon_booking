<?php

namespace App\Listeners;

use App\Enums\BookingStatus;
use App\Events\BookingCreated;
use App\Events\BookingRescheduled;
use App\Events\BookingStatusChanged;
use App\Notifications\Customer\BookingCancelledMail;
use App\Notifications\Customer\BookingConfirmedMail;
use App\Notifications\Customer\BookingReceivedMail;
use App\Notifications\Customer\BookingRejectedMail;
use App\Notifications\Customer\BookingRescheduledMail;

/** Email cho khách theo từng thay đổi của lịch hẹn (email tự vào queue) */
class SendCustomerBookingEmails
{
    public function handleCreated(BookingCreated $event): void
    {
        $booking = $event->booking;

        $booking->customer->notify($booking->status === BookingStatus::Confirmed
            ? new BookingConfirmedMail($booking)
            : new BookingReceivedMail($booking));
    }

    public function handleStatusChanged(BookingStatusChanged $event): void
    {
        $notification = match ($event->to) {
            BookingStatus::Confirmed => new BookingConfirmedMail($event->booking),
            BookingStatus::Rejected => new BookingRejectedMail($event->booking),
            BookingStatus::Cancelled => new BookingCancelledMail($event->booking),
            default => null,
        };

        if ($notification) {
            $event->booking->customer->notify($notification);
        }
    }

    public function handleRescheduled(BookingRescheduled $event): void
    {
        $event->booking->customer->notify(new BookingRescheduledMail($event->booking, $event->previousStartAt));
    }
}
