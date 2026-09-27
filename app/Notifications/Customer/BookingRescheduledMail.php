<?php

namespace App\Notifications\Customer;

use App\Models\Booking;
use Illuminate\Support\Carbon;

class BookingRescheduledMail extends BookingMail
{
    public function __construct(Booking $booking, public Carbon $previousStartAt)
    {
        parent::__construct($booking);
    }

    protected function subject(): string
    {
        return 'Lịch hẹn đã được thay đổi';
    }

    protected function heading(): string
    {
        return 'Lịch hẹn của bạn đã được đổi';
    }

    protected function paragraphs(): array
    {
        return [
            "Tiệm đã chuyển lịch hẹn của bạn sang **{$this->when()}** với **{$this->booking->staff->name}** (trước đó: {$this->previousStartAt->format('H:i d/m/Y')}).",
            'Nếu thời gian mới không phù hợp, bạn có thể hủy lịch hoặc gọi cho tiệm để đổi lại.',
        ];
    }
}
