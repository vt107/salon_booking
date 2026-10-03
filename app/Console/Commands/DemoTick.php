<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Booking;
use App\Models\User;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Support\Demo\DemoMode;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Bản demo không có ai bấm check-in: tự cho lịch hôm nay "chạy" theo giờ thực để dashboard, lịch theo ngày
 * và doanh thu hôm nay luôn sống (khách đến giờ thì vào làm, xong thì thu tiền). Đi qua BookingService như thật.
 * Một phần nhỏ lịch được bỏ qua để bookings:mark-no-show đánh dấu "không đến".
 */
#[Signature('demo:tick')]
#[Description('Bản demo: check-in / hoàn thành các lịch hôm nay đã tới giờ (chỉ khi DEMO_MODE=true)')]
class DemoTick extends Command
{
    public function handle(BookingService $bookings): int
    {
        if (! DemoMode::enabled()) {
            $this->error('DEMO_MODE đang tắt.');

            return self::FAILURE;
        }

        $by = User::where('role', UserRole::Admin)->orderBy('id')->first();

        if (! $by) {
            return self::SUCCESS;
        }

        $started = 0;
        $completed = 0;

        Booking::where('status', BookingStatus::Confirmed)
            ->whereBetween('start_at', [today(), now()])
            ->orderBy('start_at')
            ->each(function (Booking $booking) use ($bookings, $by, &$started) {
                if ($booking->id % 15 === 0) {
                    return;
                }

                try {
                    $bookings->checkIn($booking, $by);
                    $started++;
                } catch (BookingException) {
                    // Lịch vừa được xử lý ở nơi khác trong lúc chạy
                }
            });

        $methods = PaymentMethod::cases();

        Booking::where('status', BookingStatus::InProgress)
            ->where('end_at', '<=', now())
            ->orderBy('start_at')
            ->each(function (Booking $booking) use ($bookings, $by, $methods, &$completed) {
                try {
                    $bookings->complete($booking, $by, $methods[$booking->id % count($methods)]);
                    $completed++;
                } catch (BookingException) {
                    // Lịch vừa được xử lý ở nơi khác trong lúc chạy
                }
            });

        $this->info("Check-in {$started} lịch, hoàn thành {$completed} lịch.");

        return self::SUCCESS;
    }
}
