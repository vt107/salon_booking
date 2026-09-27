# Booking Salon

Website đặt lịch cho **một** tiệm Salon / Spa / Nail / Barber. Laravel 13, MySQL 8.4, Filament 5 (admin tại `/admin`), Livewire 4.

## Môi trường

Chạy hoàn toàn bằng Docker. PHP trên máy host là 8.0 nên **không** chạy `php` / `composer` trực tiếp trên host.

- `make up` / `make down`: bật / tắt app (:8000), queue, scheduler, MySQL (:3308 trên host), Mailpit (:8025)
- `make artisan c="..."`, `make composer c="..."`, `make test`, `make fresh` (migrate:fresh --seed)
- Test chạy trên database MySQL riêng `booking_salon_test` (không dùng SQLite, vì logic đặt lịch dùng `lockForUpdate`)
- Tài khoản seed: `admin@salon.test` / `password`
- Container `queue` / `scheduler` giữ code cũ trong bộ nhớ: sửa code xong chạy `make restart-workers`. Email xem ở Mailpit (:8025).
- Bot Telegram trên máy dev: điền `TELEGRAM_ADMIN_*` trong `.env` rồi `make telegram` (long polling). Server có HTTPS: `php artisan telegram:set-webhook`.
- Asset website build trên host: `make assets` (hoặc `make assets-dev`). Font Fraunces + Be Vietnam Pro tự host qua `vite.config.js`, bắt buộc có subset `vietnamese`.

## Quy ước nghiệp vụ

- Mọi booking web / Telegram đều `pending` và **admin duyệt tay**. `pending` vẫn giữ chỗ của thợ cho tới `approval_deadline_at`.
- Kiểm tra trùng lịch dùng `start_at` → `occupied_until` (= end_at + buffer của dịch vụ), chỉ với các trạng thái `BookingStatus::blocking()`.
- Chống đặt trùng: `BookingService` khóa dòng `staff` (`lockForUpdate`, nhiều thợ thì khóa một lần theo thứ tự id) rồi mới kiểm tra trùng và insert. Thứ tự khóa luôn là khách → thợ → booking.
- Connection MySQL chạy **READ COMMITTED** (`config/database.php`). Đừng đổi về REPEATABLE READ: SELECT sau khi chờ khóa sẽ đọc snapshot cũ và cho đặt trùng. `tests/Feature/Booking/ConcurrentBookingTest.php` chạy nhiều tiến trình song song để canh lỗi này.
- `booking_items` là snapshot tên / giá / thời lượng tại lúc đặt: không đọc giá hiện tại của `services` để tính tiền booking cũ.
- Tiền là VND, số nguyên. Doanh thu = tổng `bookings.total` của các booking `completed`, tính theo `paid_at`.
- Không thu cọc, không có cổng thanh toán.
- Telegram: bot nội bộ gửi cho chủ tiệm / admin (có nút duyệt). Khách nhận email; kênh Telegram cho khách (Mini App) để sau.

## Quy ước code

- Trạng thái / loại lưu `VARCHAR` + PHP backed enum trong `app/Enums` (implement `HasLabel` / `HasColor` của Filament), không dùng MySQL `ENUM`.
- Model dùng attribute `#[Fillable]` / `#[Hidden]` / `#[Scope]` như skeleton Laravel 13.
- Tên bảng pivot khai báo rõ (`staff_service`, `voucher_service`). Cột riêng của thợ trên pivot là `custom_price` / `custom_duration_minutes` (không trùng tên cột `services`, Filament mới sửa pivot được).
- Admin (Filament 5): phân quyền bằng Policy trong `app/Policies` (admin / manager / staff, xem `UserRole`); trang riêng dùng `canAccess()`. Thao tác booking dùng chung `Filament/Resources/Bookings/Actions/BookingActions` cho bảng, trang chi tiết và widget.
- View Blade tự viết cho Filament dùng CSS riêng trong view (vd `filament/pages/schedule.blade.php`): Tailwind của Filament không có class tùy ý nếu chưa dựng theme.
- Website khách: `routes/web.php` (URL tiếng Việt), controller trong `Http/Controllers/Site`, form đặt lịch là Livewire `App\Livewire\BookingWizard`. Link xem / hủy lịch của khách là signed URL (`Booking::manageUrl()`), không bao giờ lộ route không ký.
- Thông báo: email khách (`app/Notifications/Customer`, template `resources/views/mail/booking.blade.php`) và Telegram cho chủ tiệm (`app/Services/Telegram`) đều nghe event `BookingCreated` / `BookingStatusChanged` / `BookingRescheduled` trong `app/Listeners`; không gửi trực tiếp từ BookingService. Lỗi Telegram không được làm hỏng việc đặt lịch (bắt `TelegramException`, listener chạy trong queue).
- Livewire 4: không đặt tên computed / property trùng tính năng có sẵn (vd `slots`), sẽ lỗi 500 ở request cập nhật.
- Grid có phần tử cuộn ngang phải có `min-w-0` ở cột, nếu không cả trang bị giãn trên điện thoại.
- Test dùng attribute PHPUnit 12 (`#[DataProvider]`), không dùng docblock. `tests/Concerns/BuildsSalon` dựng tiệm mẫu, "hôm nay" = thứ Hai 05/10/2026 07:00.
- Logic nghiệp vụ nằm trong `app/Services` (`Booking/AvailabilityService`, `Booking/BookingService`, `Voucher/VoucherService`); Filament, controller và webhook Telegram chỉ gọi vào đó. Mọi đổi trạng thái booking đi qua `BookingService` (không `update(['status' => ...])` trực tiếp) để có khóa, lịch sử, hoàn voucher và event.
- Lỗi nghiệp vụ ném `BookingException` / `VoucherException` với message tiếng Việt hiển thị thẳng cho người dùng.
- Cấu hình vận hành trong bảng `settings`, đọc bằng `Setting::get('group.key')`. Secret (bot token...) chỉ để trong `.env`.
- Nhãn hiển thị bằng tiếng Việt.
