<?php

namespace App\Enums;

enum TelegramMessageType: string
{
    case NewBooking = 'new_booking';
    case ApprovalReminder = 'approval_reminder';
    case CustomerCancelled = 'customer_cancelled';
}
