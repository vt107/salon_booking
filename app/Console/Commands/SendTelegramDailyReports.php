<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Telegram\AdminNotifier;
use App\Services\Telegram\DailyReports;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('telegram:daily-reports')]
#[Description('Gửi lịch trong ngày (sáng) và báo cáo doanh thu (tối) theo giờ cài trong Cài đặt → Telegram')]
class SendTelegramDailyReports extends Command
{
    /** Chạy mỗi phút; giờ gửi đọc từ settings nên đổi giờ không cần sửa scheduler */
    public function handle(AdminNotifier $notifier, DailyReports $reports): int
    {
        if (! $notifier->isEnabled()) {
            return self::SUCCESS;
        }

        $now = now()->format('H:i');
        $today = today()->toDateString();

        if ($now === substr((string) Setting::get('telegram.daily_summary_time'), 0, 5) && Cache::add("telegram-summary:{$today}", true, 86400)) {
            $notifier->broadcast($reports->summary(today()));
        }

        if ($now === substr((string) Setting::get('telegram.daily_report_time'), 0, 5) && Cache::add("telegram-report:{$today}", true, 86400)) {
            $notifier->broadcast($reports->report(today()));
        }

        return self::SUCCESS;
    }
}
