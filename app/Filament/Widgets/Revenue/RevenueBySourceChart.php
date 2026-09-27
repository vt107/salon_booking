<?php

namespace App\Filament\Widgets\Revenue;

class RevenueBySourceChart extends RevenueBreakdownChart
{
    protected static ?int $sort = 5;

    protected ?string $heading = 'Theo nguồn khách';

    protected function rows(): array
    {
        return $this->report()->bySource();
    }
}
