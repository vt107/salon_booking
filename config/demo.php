<?php

/*
|--------------------------------------------------------------------------
| Chế độ demo (chỉ xem)
|--------------------------------------------------------------------------
|
| Bật DEMO_MODE=true khi deploy bản giới thiệu cho khách xem:
| - Mọi lệnh ghi SQL phát sinh từ request web bị chặn (App\Support\Demo\DemoMode),
|   trừ các bảng hạ tầng trong `writable_tables` và các câu lệnh khớp `allowed_write_patterns`.
| - Form POST / PUT / PATCH / DELETE bị chặn trước khi vào controller, trừ `allowed_routes`.
| - Nút "Demo" nổi ở góc màn hình liệt kê các khu vực + tài khoản đăng nhập sẵn (`portals`).
| - `php artisan demo:reset` dựng lại database với dữ liệu mẫu (lịch chạy hằng ngày).
|
| Lệnh artisan / queue / scheduler / test (chạy CLI) không bị chặn.
|
*/

return [

    'enabled' => (bool) env('DEMO_MODE', false),

    'message' => 'Đây là bản demo chỉ xem: thao tác thêm / sửa / xoá đã được tắt.',

    // Giờ dựng lại dữ liệu demo mỗi ngày (múi giờ app). null = không tự reset.
    'reset_at' => env('DEMO_RESET_AT', '04:00'),

    // Bảng hạ tầng vẫn cho ghi (phiên đăng nhập, cache, rate limit).
    'writable_tables' => [
        'sessions',
        'cache',
        'cache_locks',
    ],

    // Regex (so với câu SQL) cho phép ghi dù bảng không nằm trong writable_tables.
    'allowed_write_patterns' => [
        // "Ghi nhớ đăng nhập"
        '/^update [`"]?users[`"]? set [`"]?remember_token[`"]? = \?/i',
    ],

    // Route (tên hoặc pattern path) được phép nhận POST / PUT / PATCH / DELETE.
    // Request Livewire (livewire.update) luôn đi qua, lệnh ghi bên trong do guard SQL chặn.
    // Đăng nhập admin là Livewire (luôn đi qua); chỉ cần nút đăng xuất của Filament.
    'allowed_routes' => [
        'filament.admin.auth.logout',
    ],

    /*
     * Các khu vực hiển thị trong nút Demo.
     * url / login_url là path tương đối (qua helper url()).
     * accounts[].key là duy nhất toàn file: /login?demo=<key> sẽ điền sẵn tài khoản đó.
     */
    'portals' => [
        [
            'label' => 'Trang quản trị',
            'description' => 'Lịch theo ngày của từng thợ, duyệt lịch chờ, khách hàng, dịch vụ, voucher, mã QR, báo cáo doanh thu, cài đặt website và bot Telegram. Mỗi vai trò thấy menu khác nhau.',
            'url' => '/admin',
            'login_url' => '/admin/login',
            'accounts' => [
                ['key' => 'admin', 'role' => 'Chủ tiệm (quản trị)', 'email' => 'admin@salon.test', 'password' => 'password'],
                ['key' => 'manager', 'role' => 'Quản lý (duyệt lịch, danh mục, doanh thu)', 'email' => 'quanly@salon.test', 'password' => 'password'],
                ['key' => 'staff', 'role' => 'Nhân viên / thợ (xem lịch, tạo lịch hộ khách)', 'email' => 'nhanvien@salon.test', 'password' => 'password'],
            ],
        ],
        [
            'label' => 'Website đặt lịch của khách',
            'description' => 'Trang chủ, bảng giá, đội ngũ và form đặt lịch: chọn dịch vụ, ngày, khung giờ còn trống, thợ, nhập voucher. Bước gửi cuối bị tắt ở bản demo.',
            'url' => '/',
            'accounts' => [],
        ],
        [
            'label' => 'Trang lịch hẹn của khách',
            'description' => 'Link riêng (có chữ ký) gửi trong email: khách xem chi tiết lịch hẹn và tự hủy lịch.',
            'url' => '/demo/lich-hen',
            'accounts' => [],
        ],
    ],

];
