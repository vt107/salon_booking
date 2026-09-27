<?php

namespace App\Filament\Widgets\Revenue;

use Filament\Support\RawJs;

/**
 * Kiểu dáng chung cho biểu đồ doanh thu: một màu (đạt tương phản trên nền sáng lẫn tối),
 * cột mảnh bo 4px ở đầu, lưới mờ, tooltip hiện số tiền đầy đủ và số lịch.
 */
final class RevenueChartStyle
{
    public const COLOR = '#e11d48';

    /** @param  list<int>  $counts  số lịch tương ứng từng cột, hiện trong tooltip */
    public static function dataset(array $values, array $counts): array
    {
        return [
            'label' => 'Doanh thu',
            'data' => $values,
            'counts' => $counts,
            'backgroundColor' => self::COLOR,
            'hoverBackgroundColor' => '#be123c',
            'borderRadius' => 4,
            'maxBarThickness' => 28,
            'categoryPercentage' => 0.85,
            'barPercentage' => 0.9,
        ];
    }

    public static function options(bool $horizontal = false): RawJs
    {
        $valueAxis = $horizontal ? 'x' : 'y';
        $categoryAxis = $horizontal ? 'y' : 'x';
        $indexAxis = $horizontal ? "indexAxis: 'y'," : '';

        return RawJs::make(<<<JS
            {
                {$indexAxis}
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: (ctx) => {
                                const money = new Intl.NumberFormat('vi-VN').format(ctx.parsed.{$valueAxis}) + 'đ';
                                const count = ctx.dataset.counts ? ctx.dataset.counts[ctx.dataIndex] : null;
                                return count === null ? money : [money, count + ' lịch'];
                            },
                        },
                    },
                },
                scales: {
                    {$valueAxis}: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: 'rgba(127, 127, 127, 0.15)' },
                        ticks: {
                            maxTicksLimit: 6,
                            callback: (value) => value >= 1000000
                                ? (value / 1000000).toLocaleString('vi-VN', { maximumFractionDigits: 1 }) + 'tr'
                                : value >= 1000 ? (value / 1000).toLocaleString('vi-VN') + 'k' : value,
                        },
                    },
                    {$categoryAxis}: {
                        grid: { display: false },
                        border: { display: false },
                    },
                },
            }
        JS);
    }
}
