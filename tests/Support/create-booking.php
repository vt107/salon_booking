<?php

/**
 * Tạo một booking trong tiến trình PHP riêng (dùng cho ConcurrentBookingTest).
 * Tham số: <sđt> <Y-m-d H:i> <service_id> <staff_id|any> <go_at>. In ra JSON kết quả.
 * go_at (unix time, số thực): mọi tiến trình khởi động xong thì chờ tới cùng thời điểm này mới đặt lịch.
 */

use App\Services\Booking\BookingData;
use App\Services\Booking\BookingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $phone, $startAt, $serviceId, $staffId, $goAt] = $argv;

// Máy chậm có thể khởi động xong sau mốc: time_sleep_until() với mốc đã qua phát warning => exception
if ((float) $goAt > microtime(true)) {
    time_sleep_until((float) $goAt);
}

try {
    $booking = app(BookingService::class)->create(new BookingData(
        customerName: 'Khách '.$phone,
        customerPhone: $phone,
        serviceIds: [(int) $serviceId],
        startAt: Carbon::parse($startAt),
        staffId: $staffId === 'any' ? null : (int) $staffId,
    ));

    echo json_encode(['ok' => true, 'staff_id' => $booking->staff_id]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => class_basename($e).': '.$e->getMessage()]);
}
