<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\TelegramMessageType;
use App\Models\Booking;
use App\Models\Setting;
use App\Services\Telegram\AdminNotifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:remind-pending-approvals')]
#[Description('Nhắc trên Telegram các lịch chờ duyệt sắp hết hạn')]
class RemindPendingApprovals extends Command
{
    public function handle(AdminNotifier $notifier): int
    {
        if (! $notifier->isEnabled()) {
            return self::SUCCESS;
        }

        $minutes = (int) Setting::get('telegram.approval_reminder_minutes', 15);

        Booking::where('status', BookingStatus::Pending)
            ->whereBetween('approval_deadline_at', [now(), now()->addMinutes($minutes)])
            ->whereDoesntHave('telegramMessages', fn ($q) => $q->where('type', TelegramMessageType::ApprovalReminder))
            ->orderBy('approval_deadline_at')
            ->each(fn (Booking $booking) => $notifier->approvalReminder($booking));

        return self::SUCCESS;
    }
}
