<?php

namespace App\Services\Voucher;

use App\Enums\BookingStatus;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Support\Carbon;

class VoucherService
{
    /**
     * Kiểm tra voucher và tính số tiền giảm cho các dòng dịch vụ.
     *
     * @param  list<array{service_id: int, price: int}>  $items
     *
     * @throws VoucherException
     */
    public function quote(string $code, array $items, ?Customer $customer = null, ?Carbon $at = null): VoucherQuote
    {
        $at ??= now();
        $voucher = Voucher::with('services:id')
            ->where('code', mb_strtoupper(trim($code)))
            ->where('is_active', true)
            ->first();

        if (! $voucher) {
            throw VoucherException::notFound();
        }
        if ($voucher->starts_at && $at->lt($voucher->starts_at)) {
            throw VoucherException::notStarted();
        }
        if ($voucher->ends_at && $at->gt($voucher->ends_at)) {
            throw VoucherException::expired();
        }
        if ($voucher->usage_limit !== null && $voucher->used_count >= $voucher->usage_limit) {
            throw VoucherException::exhausted();
        }

        if ($customer) {
            $this->checkCustomer($voucher, $customer);
        }

        $subtotal = array_sum(array_column($items, 'price'));
        if ($subtotal < $voucher->min_order_amount) {
            throw VoucherException::minOrderNotMet($voucher->min_order_amount);
        }

        // Các dòng được giảm: tất cả, hoặc chỉ dịch vụ nằm trong phạm vi voucher
        $eligible = array_filter($items, fn (array $item) => $voucher->scope === VoucherScope::All
            || $voucher->services->contains('id', $item['service_id']));
        $eligibleTotal = array_sum(array_column($eligible, 'price'));

        if ($eligibleTotal === 0) {
            throw VoucherException::notApplicable();
        }

        $discount = match ($voucher->type) {
            VoucherType::Fixed => min($voucher->value, $eligibleTotal),
            VoucherType::Percent => min(
                intdiv($eligibleTotal * $voucher->value, 100),
                $voucher->max_discount ?? PHP_INT_MAX,
            ),
        };

        return new VoucherQuote($voucher, $discount, $this->allocate($eligible, $eligibleTotal, $discount));
    }

    /**
     * Ghi nhận lượt dùng. Gọi bên trong transaction tạo booking.
     *
     * @throws VoucherException
     */
    public function redeem(VoucherQuote $quote, Booking $booking): VoucherUsage
    {
        // Tăng lượt nguyên tử: hai khách dùng lượt cuối cùng lúc thì chỉ một người được
        $updated = Voucher::whereKey($quote->voucher->id)
            ->where(fn ($q) => $q->whereNull('usage_limit')->orWhereColumn('used_count', '<', 'usage_limit'))
            ->increment('used_count');

        if ($updated === 0) {
            throw VoucherException::exhausted();
        }

        return VoucherUsage::create([
            'voucher_id' => $quote->voucher->id,
            'booking_id' => $booking->id,
            'customer_id' => $booking->customer_id,
            'discount_amount' => $quote->discount,
        ]);
    }

    /** Trả lại lượt dùng khi booking bị hủy / từ chối */
    public function release(Booking $booking): void
    {
        $usage = VoucherUsage::where('booking_id', $booking->id)->whereNull('released_at')->first();

        if (! $usage) {
            return;
        }

        $usage->update(['released_at' => now()]);
        Voucher::withTrashed()->whereKey($usage->voucher_id)->where('used_count', '>', 0)->decrement('used_count');
    }

    private function checkCustomer(Voucher $voucher, Customer $customer): void
    {
        if ($voucher->usage_limit_per_customer !== null) {
            $used = VoucherUsage::where('voucher_id', $voucher->id)
                ->where('customer_id', $customer->id)
                ->whereNull('released_at')
                ->count();

            if ($used >= $voucher->usage_limit_per_customer) {
                throw VoucherException::customerLimitReached();
            }
        }

        if ($voucher->first_booking_only) {
            $hasBooked = $customer->bookings()
                ->whereNotIn('status', [BookingStatus::Rejected, BookingStatus::Cancelled])
                ->exists();

            if ($hasBooked) {
                throw VoucherException::firstBookingOnly();
            }
        }
    }

    /**
     * Chia số tiền giảm theo tỉ lệ giá các dòng được giảm; phần dư do làm tròn dồn vào dòng cuối.
     *
     * @param  array<int, array{service_id: int, price: int}>  $eligible
     * @return array<int, int>
     */
    private function allocate(array $eligible, int $eligibleTotal, int $discount): array
    {
        $allocations = [];
        $remaining = $discount;
        $lastKey = array_key_last($eligible);

        foreach ($eligible as $key => $item) {
            $share = $key === $lastKey ? $remaining : intdiv($discount * $item['price'], $eligibleTotal);
            $allocations[$key] = $share;
            $remaining -= $share;
        }

        return $allocations;
    }
}
