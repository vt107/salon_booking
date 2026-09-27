<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Pages\Schedule;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class BookingStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $pending = Booking::where('status', BookingStatus::Pending)->count();
        $today = Booking::blocking()->whereBetween('start_at', [today(), today()->endOfDay()])->count();
        $revenueToday = $this->revenue(today(), today()->endOfDay());
        $revenueMonth = $this->revenue(today()->startOfMonth(), now());
        $revenueLastMonth = $this->revenue(today()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow());

        return [
            Stat::make('Chờ duyệt', $pending)
                ->description($pending ? 'Bấm để duyệt' : 'Không có lịch chờ')
                ->color($pending ? 'warning' : 'gray')
                ->url(BookingResource::getUrl('index', ['tab' => 'pending'])),
            Stat::make('Lịch hôm nay', $today)
                ->description('Xem lịch theo thợ')
                ->url(Schedule::getUrl()),
            Stat::make('Doanh thu hôm nay', Money::format($revenueToday)),
            Stat::make('Doanh thu tháng này', Money::format($revenueMonth))
                ->description($revenueLastMonth
                    ? sprintf('%+d%% so với cùng kỳ tháng trước', round(($revenueMonth - $revenueLastMonth) / $revenueLastMonth * 100))
                    : null)
                ->color($revenueMonth >= $revenueLastMonth ? 'success' : 'danger'),
        ];
    }

    private function revenue($from, $to): int
    {
        return (int) Booking::where('status', BookingStatus::Completed)->whereBetween('paid_at', [$from, $to])->sum('total');
    }
}
