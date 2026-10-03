<?php

namespace Database\Seeders;

use App\Support\Demo\DemoMode;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            SettingSeeder::class,
            BusinessHourSeeder::class,
            AdminUserSeeder::class,
            ServiceSeeder::class,
            StaffSeeder::class,
        ]);

        // Máy dev và bản demo chỉ xem (DEMO_MODE=true, demo:reset) có dữ liệu mẫu đầy đủ
        if (app()->isLocal() || DemoMode::enabled()) {
            $this->call(DemoSeeder::class);
        }
    }
}
