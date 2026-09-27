<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Notifications\Customer\BookingReminderMail;
use App\Services\Booking\BookingSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:send-reminders')]
#[Description('Email nhắc khách các lịch hẹn đã xác nhận sắp tới')]
class SendBookingReminders extends Command
{
    public function handle(BookingSettings $settings): int
    {
        $hours = $settings->reminderHoursBefore();
        $sent = 0;

        Booking::with('customer')
            ->where('status', BookingStatus::Confirmed)
            ->whereNull('reminder_sent_at')
            ->whereBetween('start_at', [now(), now()->addHours($hours)])
            ->orderBy('id')
            ->each(function (Booking $booking) use ($hours, &$sent) {
                // Lịch vừa được xác nhận sát giờ hẹn thì email xác nhận đã đủ, không nhắc thêm
                $confirmedLate = $booking->confirmed_at?->gt($booking->start_at->copy()->subHours($hours)->addHour());

                if (! $confirmedLate && filled($booking->customer->email)) {
                    $booking->customer->notify(new BookingReminderMail($booking));
                    $sent++;
                }

                $booking->update(['reminder_sent_at' => now()]);
            });

        $this->info("Đã gửi {$sent} email nhắc lịch.");

        return self::SUCCESS;
    }
}
