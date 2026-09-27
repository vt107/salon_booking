<?php

namespace App\Listeners;

use App\Models\NotificationLog;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;

/** Ghi lại mọi thông báo gửi cho khách / nhân viên vào notification_logs */
class LogNotifications
{
    public function handleSent(NotificationSent $event): void
    {
        $this->log($event->notifiable, $event->notification, $event->channel, 'sent');
    }

    public function handleFailed(NotificationFailed $event): void
    {
        $error = $event->data['exception'] ?? null;

        $this->log($event->notifiable, $event->notification, $event->channel, 'failed', $error instanceof \Throwable ? $error->getMessage() : null);
    }

    private function log(object $notifiable, object $notification, string $channel, string $status, ?string $error = null): void
    {
        NotificationLog::create([
            'booking_id' => $notification->booking->id ?? null,
            'notifiable_type' => method_exists($notifiable, 'getMorphClass') ? $notifiable->getMorphClass() : null,
            'notifiable_id' => $notifiable->id ?? null,
            'type' => class_basename($notification),
            'channel' => $channel,
            'recipient' => (string) $notifiable->routeNotificationFor($channel, $notification),
            'status' => $status,
            'error' => $error,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }
}
