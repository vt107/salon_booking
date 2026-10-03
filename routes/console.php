<?php

use App\Support\Demo\DemoMode;
use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:mark-no-show')->everyFiveMinutes()->withoutOverlapping();
// Bản demo: khách là dữ liệu mẫu, không gửi email nhắc lịch
Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping()->skip(fn () => DemoMode::enabled());
Schedule::command('bookings:remind-pending-approvals')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:daily-reports')->everyMinute()->withoutOverlapping();

// Chế độ demo (config/demo.php): dựng lại dữ liệu mỗi ngày, trong ngày cho lịch hôm nay "chạy" theo giờ thực
if (config('demo.reset_at')) {
    Schedule::command('demo:reset --force')->dailyAt(config('demo.reset_at'))->when(fn () => DemoMode::enabled());
}
Schedule::command('demo:tick')->everyFiveMinutes()->withoutOverlapping()->when(fn () => DemoMode::enabled());
