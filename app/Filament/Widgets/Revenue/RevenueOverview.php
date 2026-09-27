<?php

namespace App\Filament\Widgets\Revenue;

use App\Services\Reports\RevenueReport;
use App\Support\Money;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RevenueOverview extends StatsOverviewWidget
{
    use ReadsRevenueFilters;

    protected static ?int $sort = 1;

    protected static bool $isDiscovered = false;

    protected function getStats(): array
    {
        $report = $this->report();
        $now = $report->summary();
        $before = $report->previous()->summary();

        return [
            Stat::make('Doanh thu', Money::format($now['revenue']))
                ->description($this->changeText(RevenueReport::change($now['revenue'], $before['revenue'])))
                ->descriptionIcon($this->changeIcon($now['revenue'], $before['revenue']))
                ->color($this->changeColor($now['revenue'], $before['revenue']))
                ->chart(array_column($report->timeline(), 'revenue'))
                ->chartColor('primary'),
            Stat::make('Lịch hoàn thành', $now['completed'])
                ->description($this->changeText(RevenueReport::change($now['completed'], $before['completed'])))
                ->descriptionIcon($this->changeIcon($now['completed'], $before['completed']))
                ->color($this->changeColor($now['completed'], $before['completed'])),
            Stat::make('Trung bình mỗi lịch', Money::format($now['average']))
                ->description($now['discount'] ? 'Đã giảm giá '.Money::format($now['discount']) : 'Không có giảm giá'),
            Stat::make('Khách được phục vụ', $now['customers'])
                ->description($now['new_customers'].' khách mới · '.($now['customers'] - $now['new_customers']).' khách quay lại'),
            Stat::make('Hủy & không đến', $now['lost_rate'] === null ? '—' : str_replace('.', ',', (string) $now['lost_rate']).'%')
                ->description("{$now['cancelled']} hủy · {$now['no_show']} không đến / {$now['resolved']} lịch")
                // Tỉ lệ này càng thấp càng tốt: màu ngược với doanh thu
                ->color($now['lost_rate'] !== null && $before['lost_rate'] !== null
                    ? ($now['lost_rate'] <= $before['lost_rate'] ? 'success' : 'danger')
                    : null),
        ];
    }

    protected function getHeading(): ?string
    {
        return 'Tổng quan '.$this->report()->label();
    }

    protected function getDescription(): ?string
    {
        return 'So với '.$this->report()->previous()->label();
    }

    private function changeText(?float $change): string
    {
        return $change === null ? 'Kỳ trước chưa có số liệu' : sprintf('%s%s%% so với kỳ trước', $change > 0 ? '+' : '', str_replace('.', ',', (string) $change));
    }

    private function changeIcon(int $now, int $before): ?Heroicon
    {
        return match (true) {
            $now > $before => Heroicon::ArrowTrendingUp,
            $now < $before => Heroicon::ArrowTrendingDown,
            default => null,
        };
    }

    private function changeColor(int $now, int $before): ?string
    {
        return match (true) {
            $now > $before => 'success',
            $now < $before => 'danger',
            default => null,
        };
    }
}
