<?php

namespace App\Events;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  User|Customer|null  $actor  null = hệ thống
     */
    public function __construct(
        public Booking $booking,
        public BookingStatus $from,
        public BookingStatus $to,
        public User|Customer|null $actor = null,
    ) {}
}
