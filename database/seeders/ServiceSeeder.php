<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        // [tên, giá, giá tối đa, thời lượng, buffer]
        $catalog = [
            'Cắt tóc' => [
                ['Cắt tóc nam', 100_000, null, 30, 5],
                ['Cắt tóc nữ', 150_000, 250_000, 45, 5],
                ['Cạo mặt & ráy tai', 60_000, null, 20, 5],
            ],
            'Gội & Sấy' => [
                ['Gội dưỡng sinh', 120_000, null, 45, 5],
                ['Gội sấy tạo kiểu', 80_000, null, 30, 5],
            ],
            'Nhuộm & Uốn' => [
                ['Nhuộm thời trang', 450_000, 900_000, 90, 10],
                ['Uốn / duỗi', 500_000, 1_200_000, 120, 10],
            ],
            'Nail' => [
                ['Sơn gel tay', 150_000, null, 45, 10],
                ['Đắp bột / úp móng', 300_000, 450_000, 75, 10],
                ['Chăm sóc móng chân', 120_000, null, 40, 10],
            ],
            'Spa' => [
                ['Chăm sóc da mặt cơ bản', 350_000, null, 60, 15],
                ['Massage body thư giãn', 400_000, null, 60, 15],
            ],
        ];

        foreach (array_keys($catalog) as $i => $categoryName) {
            $category = ServiceCategory::updateOrCreate(['slug' => Str::slug($categoryName)], [
                'name' => $categoryName,
                'sort_order' => $i,
            ]);

            foreach ($catalog[$categoryName] as $j => [$name, $price, $priceMax, $duration, $buffer]) {
                Service::updateOrCreate(['slug' => Str::slug($name)], [
                    'category_id' => $category->id,
                    'name' => $name,
                    'price' => $price,
                    'price_max' => $priceMax,
                    'duration_minutes' => $duration,
                    'buffer_minutes' => $buffer,
                    'is_featured' => $j === 0,
                    'sort_order' => $j,
                ]);
            }
        }
    }
}
