<?php

namespace App\Filament;

use Filament\Support\Contracts\HasLabel;

/** Nhóm menu admin, thứ tự khai báo = thứ tự hiển thị */
enum NavigationGroup implements HasLabel
{
    case Bookings;
    case Reports;
    case Customers;
    case Catalog;
    case Marketing;
    case System;

    public function getLabel(): string
    {
        return match ($this) {
            self::Bookings => 'Lịch hẹn',
            self::Reports => 'Báo cáo',
            self::Customers => 'Khách hàng',
            self::Catalog => 'Dịch vụ & Nhân viên',
            self::Marketing => 'Khuyến mãi',
            self::System => 'Hệ thống',
        };
    }
}
