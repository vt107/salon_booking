<?php

namespace App\Filament\Widgets\Revenue;

class RevenueByPaymentChart extends RevenueBreakdownChart
{
    protected static ?int $sort = 6;

    protected ?string $heading = 'Theo hình thức thanh toán';

    protected function rows(): array
    {
        return $this->report()->byPaymentMethod();
    }
}
