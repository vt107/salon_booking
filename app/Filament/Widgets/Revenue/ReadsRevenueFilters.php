<?php

namespace App\Filament\Widgets\Revenue;

use App\Services\Reports\RevenueReport;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/** Widget của trang Doanh thu: đọc kỳ báo cáo từ bộ lọc trang, chỉ admin / quản lý xem được */
trait ReadsRevenueFilters
{
    use InteractsWithPageFilters;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->role->canManageCatalog();
    }

    protected function report(): RevenueReport
    {
        return RevenueReport::fromFilters($this->pageFilters);
    }
}
