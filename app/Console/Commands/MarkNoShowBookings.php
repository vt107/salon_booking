<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\BookingSettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:mark-no-show')]
#[Description('Đánh dấu "không đến" cho booking đã xác nhận nhưng quá giờ hẹn mà chưa check-in')]
class MarkNoShowBookings extends Command
{
    public function handle(BookingService $bookings, BookingSettings $settings): int
    {
        $count = 0;

        Booking::where('status', BookingStatus::Confirmed)
            ->where('start_at', '<=', now()->subMinutes($settings->noShowGraceMinutes()))
            ->orderBy('id')
            ->each(function (Booking $booking) use ($bookings, &$count) {
                try {
                    $bookings->markNoShow($booking);
                    $count++;
                } catch (BookingException) {
                    // Booking vừa được xử lý ở nơi khác (check-in, hủy...) trong lúc chạy
                }
            });

        $this->info("Đã đánh dấu {$count} booking không đến.");

        return self::SUCCESS;
    }
}
