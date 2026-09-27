<?php

namespace App\Filament\Widgets\Revenue;

class RevenueByServiceChart extends RevenueBreakdownChart
{
    protected static ?int $sort = 4;

    protected ?string $heading = 'Theo dịch vụ';

    protected function rows(): array
    {
        return $this->report()->byService();
    }
}
