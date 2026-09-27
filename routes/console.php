<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('bookings:expire-pending')->everyMinute()->withoutOverlapping();
Schedule::command('bookings:mark-no-show')->everyFiveMinutes()->withoutOverlapping();
