<?php

namespace App\Models;

use App\Enums\BookingSource;
use App\Enums\BookingStatus;
use App\Enums\CancelledBy;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

#[Fillable([
    'code', 'customer_id', 'staff_id', 'start_at', 'end_at', 'occupied_until',
    'status', 'approval_deadline_at', 'source', 'qr_code_id', 'is_staff_auto_assigned',
    'subtotal', 'discount_amount', 'total', 'voucher_id', 'payment_method', 'paid_at',
    'customer_note', 'internal_note',
    'confirmed_at', 'confirmed_by', 'rejected_at', 'cancelled_at', 'cancelled_by', 'status_reason',
    'checked_in_at', 'completed_at', 'reminder_sent_at', 'created_by',
])]
class Booking extends Model
{
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'occupied_until' => 'datetime',
            'status' => BookingStatus::class,
            'approval_deadline_at' => 'datetime',
            'source' => BookingSource::class,
            'is_staff_auto_assigned' => 'boolean',
            'subtotal' => 'integer',
            'discount_amount' => 'integer',
            'total' => 'integer',
            'payment_method' => PaymentMethod::class,
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'cancelled_by' => CancelledBy::class,
            'checked_in_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Link riêng để khách xem / hủy lịch (gửi trong email). Có chữ ký nên không đoán được từ mã booking.
     */
    public function manageUrl(): string
    {
        return URL::signedRoute('booking.show', ['booking' => $this->code]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(Staff::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class)->orderBy('sort_order');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(BookingStatusHistory::class)->latest('id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class)->withTrashed();
    }

    public function voucherUsage(): HasOne
    {
        return $this->hasOne(VoucherUsage::class);
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function telegramMessages(): HasMany
    {
        return $this->hasMany(TelegramMessage::class);
    }

    /** Các booking đang giữ chỗ của thợ */
    #[Scope]
    protected function blocking(Builder $query): void
    {
        $query->whereIn('status', BookingStatus::blocking());
    }

    /** Các booking có khoảng chiếm chỗ giao với [$from, $to) */
    #[Scope]
    protected function overlapping(Builder $query, Carbon $from, Carbon $to): void
    {
        $query->where('start_at', '<', $to)->where('occupied_until', '>', $from);
    }
}
