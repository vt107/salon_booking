<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\Booking\BookingSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'shop' => [
                'name' => 'Booking Salon',
                'address' => '123 Nguyễn Trãi, Quận 1, TP. Hồ Chí Minh',
                'phone' => '0901234567',
                'email' => 'booking@salon.test',
                'logo' => null,
                'map_url' => null,
                'facebook' => null,
                'zalo' => null,
            ],
            'booking' => BookingSettings::DEFAULTS,
            'telegram' => [
                'group_chat_id' => null,
                'approval_reminder_minutes' => 15,
                'daily_summary_time' => '07:00',
                'daily_report_time' => '21:00',
            ],
        ];

        foreach ($defaults as $group => $items) {
            foreach ($items as $key => $value) {
                Setting::firstOrCreate(['group' => $group, 'key' => $key], ['value' => $value]);
            }
        }

        Cache::forget('settings.all');
    }
}
