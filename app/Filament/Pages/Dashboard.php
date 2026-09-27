<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\BookingStats;
use App\Filament\Widgets\PendingBookings;
use Filament\Pages\Dashboard as BaseDashboard;

/** Bảng điều khiển hằng ngày: lịch chờ duyệt, lịch hôm nay, doanh thu nhanh */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            BookingStats::class,
            PendingBookings::class,
        ];
    }
}
