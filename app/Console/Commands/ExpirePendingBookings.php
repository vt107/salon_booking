<?php

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bookings:expire-pending')]
#[Description('Tự hủy các booking chờ duyệt đã quá hạn xác nhận')]
class ExpirePendingBookings extends Command
{
    public function handle(BookingService $bookings): int
    {
        $expired = 0;

        Booking::where('status', BookingStatus::Pending)
            ->where('approval_deadline_at', '<=', now())
            ->orderBy('id')
            ->each(function (Booking $booking) use ($bookings, &$expired) {
                try {
                    $expired += (int) $bookings->expire($booking);
                } catch (BookingException) {
                    // Admin vừa duyệt / từ chối booking này trong lúc chạy
                }
            });

        $this->info("Đã hủy {$expired} booking quá hạn duyệt.");

        return self::SUCCESS;
    }
}
