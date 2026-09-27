<?php

namespace App\Support;

/** Thứ trong tuần theo Carbon::dayOfWeek (0 = Chủ nhật) */
class Weekday
{
    private const LABELS = [
        1 => 'Thứ Hai',
        2 => 'Thứ Ba',
        3 => 'Thứ Tư',
        4 => 'Thứ Năm',
        5 => 'Thứ Sáu',
        6 => 'Thứ Bảy',
        0 => 'Chủ nhật',
    ];

    /** @return array<int, string> Thứ Hai → Chủ nhật */
    public static function options(): array
    {
        return self::LABELS;
    }

    public static function label(int $day): string
    {
        return self::LABELS[$day];
    }
}
