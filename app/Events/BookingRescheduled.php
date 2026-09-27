<?php

namespace App\Events;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class BookingRescheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Booking $booking,
        public Carbon $previousStartAt,
        public int $previousStaffId,
        public User $by,
    ) {}
}
