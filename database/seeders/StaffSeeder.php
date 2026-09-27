<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\Staff;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        // [tên, chức danh, danh mục dịch vụ, ngày nghỉ trong tuần (0 = CN), ca làm, [slug dịch vụ => giá riêng]]
        $team = [
            ['Minh Tuấn', 'Senior Stylist', ['cat-toc', 'goi-say', 'nhuom-uon'], [1], [['09:00', '18:00']], ['cat-toc-nam' => 150_000, 'cat-toc-nu' => 200_000]],
            ['Hoàng Nam', 'Barber', ['cat-toc', 'goi-say'], [3], [['08:30', '12:00'], ['13:30', '20:30']], []],
            ['Thu Trang', 'Nail Artist', ['nail'], [2], [['09:00', '19:00']], []],
            ['Ngọc Ánh', 'Nail Technician', ['nail'], [4], [['10:00', '20:30']], []],
            ['Lan Hương', 'Chuyên viên Spa', ['spa', 'goi-say'], [0], [['09:00', '18:00']], []],
        ];

        foreach ($team as $i => [$name, $title, $categorySlugs, $daysOff, $shifts, $customPrices]) {
            $staff = Staff::updateOrCreate(['slug' => Str::slug($name)], [
                'name' => $name,
                'title' => $title,
                'sort_order' => $i,
            ]);

            $services = Service::whereHas('category', fn ($q) => $q->whereIn('slug', $categorySlugs))->get();

            $staff->services()->sync($services->mapWithKeys(fn (Service $service) => [
                $service->id => ['custom_price' => $customPrices[$service->slug] ?? null],
            ]));

            $staff->schedules()->delete();

            foreach (range(0, 6) as $day) {
                if (in_array($day, $daysOff, true)) {
                    continue;
                }

                foreach ($shifts as [$start, $end]) {
                    $staff->schedules()->create(['day_of_week' => $day, 'start_time' => $start, 'end_time' => $end]);
                }
            }
        }
    }
}
