<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BookingStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case NoShow = 'no_show';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Chờ duyệt',
            self::Confirmed => 'Đã xác nhận',
            self::Rejected => 'Từ chối',
            self::Cancelled => 'Đã hủy',
            self::InProgress => 'Đang làm',
            self::Completed => 'Hoàn thành',
            self::NoShow => 'Không đến',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::InProgress => 'primary',
            self::Completed => 'success',
            self::Rejected, self::Cancelled, self::NoShow => 'danger',
        };
    }

    /**
     * Các trạng thái đang giữ chỗ của thợ (pending cũng giữ chỗ trong lúc chờ duyệt).
     *
     * @return list<self>
     */
    public static function blocking(): array
    {
        return [self::Pending, self::Confirmed, self::InProgress];
    }

    public function isBlocking(): bool
    {
        return in_array($this, self::blocking(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Cancelled, self::Completed, self::NoShow], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Rejected, self::Cancelled],
            self::Confirmed => [self::InProgress, self::Cancelled, self::NoShow],
            self::InProgress => [self::Completed],
            default => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }
}
