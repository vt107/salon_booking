<?php

namespace App\Filament\Widgets\Revenue;

class RevenueByStaffChart extends RevenueBreakdownChart
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Theo nhân viên';

    protected function rows(): array
    {
        return $this->report()->byStaff();
    }
}
