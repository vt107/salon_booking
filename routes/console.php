<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:mark-no-show')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bookings:send-reminders')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('bookings:remind-pending-approvals')->everyMinute()->withoutOverlapping();
Schedule::command('telegram:daily-reports')->everyMinute()->withoutOverlapping();
