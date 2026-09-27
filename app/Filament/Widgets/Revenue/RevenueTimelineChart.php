<?php

namespace App\Filament\Widgets\Revenue;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class RevenueTimelineChart extends ChartWidget
{
    use ReadsRevenueFilters;

    protected static ?int $sort = 2;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '280px';

    public function getHeading(): string
    {
        return $this->report()->groupsByMonth() ? 'Doanh thu theo tháng' : 'Doanh thu theo ngày';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $points = $this->report()->timeline();

        return [
            'labels' => array_column($points, 'label'),
            'datasets' => [RevenueChartStyle::dataset(array_column($points, 'revenue'), array_column($points, 'count'))],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RevenueChartStyle::options();
    }
}
