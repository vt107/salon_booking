<?php

namespace App\Filament\Widgets\Revenue;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/** Thanh ngang xếp từ cao xuống thấp: doanh thu theo một chiều (thợ, dịch vụ, nguồn...) */
abstract class RevenueBreakdownChart extends ChartWidget
{
    use ReadsRevenueFilters;

    protected static bool $isDiscovered = false;

    /** @return list<array{label: string, revenue: int, count: int}> */
    abstract protected function rows(): array;

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rows = $this->rows();

        return [
            'labels' => array_column($rows, 'label'),
            'datasets' => [RevenueChartStyle::dataset(array_column($rows, 'revenue'), array_column($rows, 'count'))],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RevenueChartStyle::options(horizontal: true);
    }

    protected function getMaxHeight(): ?string
    {
        return max(160, count($this->rows()) * 36 + 40).'px';
    }
}
