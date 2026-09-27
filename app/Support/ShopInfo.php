<?php

namespace App\Support;

use App\Models\BusinessHour;
use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Thông tin tiệm hiển thị trên website (đọc từ trang Cài đặt).
 */
class ShopInfo
{
    public function name(): string
    {
        return Setting::get('shop.name') ?: config('app.name');
    }

    public function get(string $key): ?string
    {
        return Setting::get("shop.{$key}") ?: null;
    }

    public function logoUrl(): ?string
    {
        $logo = $this->get('logo');

        return $logo ? Storage::disk('public')->url($logo) : null;
    }

    public function zaloUrl(): ?string
    {
        $zalo = $this->get('zalo');

        return $zalo && ! str_starts_with($zalo, 'http') ? 'https://zalo.me/'.preg_replace('/\D/', '', $zalo) : $zalo;
    }

    /**
     * Giờ mở cửa gộp các ngày liền nhau có cùng giờ: "Thứ Hai – Thứ Bảy" => "08:30 – 20:30".
     *
     * @return list<array{days: string, hours: string}>
     */
    public function openingHours(): array
    {
        $hours = BusinessHour::all()->keyBy('day_of_week');
        $groups = [];

        foreach (Weekday::options() as $day => $label) {
            $row = $hours->get($day);
            $text = ! $row || $row->is_closed ? 'Nghỉ' : substr($row->open_time, 0, 5).' – '.substr($row->close_time, 0, 5);
            $last = array_key_last($groups);

            if ($last !== null && $groups[$last]['hours'] === $text) {
                $groups[$last]['to'] = $label;
            } else {
                $groups[] = ['from' => $label, 'to' => null, 'hours' => $text];
            }
        }

        return array_map(fn (array $g) => [
            'days' => $g['to'] ? "{$g['from']} – {$g['to']}" : $g['from'],
            'hours' => $g['hours'],
        ], $groups);
    }

    public function todayHours(): ?string
    {
        $row = BusinessHour::firstWhere('day_of_week', today()->dayOfWeek);

        return $row && ! $row->is_closed ? substr($row->open_time, 0, 5).' – '.substr($row->close_time, 0, 5) : null;
    }
}
