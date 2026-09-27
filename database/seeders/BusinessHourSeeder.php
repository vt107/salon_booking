<?php

namespace Database\Seeders;

use App\Models\BusinessHour;
use Illuminate\Database\Seeder;

class BusinessHourSeeder extends Seeder
{
    public function run(): void
    {
        foreach (range(0, 6) as $day) {
            BusinessHour::updateOrCreate(['day_of_week' => $day], [
                'open_time' => '08:30',
                'close_time' => '20:30',
                'is_closed' => false,
            ]);
        }
    }
}
