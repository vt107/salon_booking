<?php

namespace App\Listeners;

use App\Events\BookingCreated;
use App\Events\BookingRescheduled;
use App\Events\BookingStatusChanged;
use App\Models\Customer;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Báo chủ tiệm qua Telegram. Chạy trong queue để Telegram chậm / lỗi không ảnh hưởng việc đặt lịch.
 */
class NotifyAdminsOnTelegram implements ShouldQueue
{
    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(private AdminNotifier $notifier) {}

    public function handleCreated(BookingCreated $event): void
    {
        // Lịch do nhân viên tạo hộ khách thì tiệm đã biết, không cần báo
        if ($event->booking->created_by === null && $this->notifier->isEnabled()) {
            $this->notifier->newBooking($event->booking);
        }
    }

    public function handleStatusChanged(BookingStatusChanged $event): void
    {
        if (! $this->notifier->isEnabled()) {
            return;
        }

        $this->notifier->refresh($event->booking);

        if ($event->actor instanceof Customer) {
            $this->notifier->customerCancelled($event->booking);
        }
    }

    public function handleRescheduled(BookingRescheduled $event): void
    {
        if ($this->notifier->isEnabled()) {
            $this->notifier->refresh($event->booking);
        }
    }
}
