<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Customer;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Services\Voucher\VoucherException;
use App\Services\Voucher\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class VoucherServiceTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private VoucherService $vouchers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildSalon();
        $this->vouchers = app(VoucherService::class);
    }

    private function voucher(array $attributes = []): Voucher
    {
        return Voucher::create([
            'code' => 'SALE',
            'name' => 'Khuyến mãi',
            'type' => VoucherType::Percent,
            'value' => 10,
            'scope' => VoucherScope::All,
            ...$attributes,
        ]);
    }

    /** @return list<array{service_id: int, price: int}> */
    private function items(int ...$prices): array
    {
        $services = [$this->haircut, $this->coloring, $this->gelNails];

        return array_map(fn (int $price, int $i) => ['service_id' => $services[$i]->id, 'price' => $price], $prices, array_keys($prices));
    }

    private function assertRejected(string $message, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected VoucherException');
        } catch (VoucherException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    public function test_percent_discount_is_capped_and_code_is_case_insensitive(): void
    {
        $this->voucher(['value' => 20, 'max_discount' => 50_000]);

        $this->assertSame(20_000, $this->vouchers->quote(' sale ', $this->items(100_000))->discount);
        $this->assertSame(50_000, $this->vouchers->quote('sale', $this->items(100_000, 450_000))->discount);
    }

    public function test_fixed_discount_never_exceeds_the_eligible_amount(): void
    {
        $this->voucher(['type' => VoucherType::Fixed, 'value' => 150_000]);

        $this->assertSame(100_000, $this->vouchers->quote('SALE', $this->items(100_000))->discount);
    }

    public function test_discount_is_allocated_proportionally_with_remainder_on_last_item(): void
    {
        $this->voucher(['type' => VoucherType::Fixed, 'value' => 100_000]);

        $quote = $this->vouchers->quote('SALE', $this->items(100_000, 200_000));

        $this->assertSame([0 => 33_333, 1 => 66_667], $quote->allocations);
    }

    public function test_service_scoped_voucher_only_discounts_matching_services(): void
    {
        $voucher = $this->voucher(['value' => 20, 'scope' => VoucherScope::Services]);
        $voucher->services()->attach($this->coloring);

        $quote = $this->vouchers->quote('SALE', $this->items(100_000, 450_000));

        $this->assertSame(90_000, $quote->discount);
        $this->assertSame([1 => 90_000], $quote->allocations);

        $this->assertRejected('Mã giảm giá không áp dụng cho các dịch vụ đã chọn.', fn () => $this->vouchers->quote('SALE', $this->items(100_000)));
    }

    public function test_minimum_order_amount(): void
    {
        $this->voucher(['min_order_amount' => 300_000]);

        $this->assertRejected('Mã giảm giá áp dụng cho đơn từ 300.000đ.', fn () => $this->vouchers->quote('SALE', $this->items(100_000)));
    }

    public function test_unknown_inactive_expired_and_not_started_vouchers_are_rejected(): void
    {
        $this->voucher(['code' => 'OFF', 'is_active' => false]);
        $this->voucher(['code' => 'OLD', 'ends_at' => now()->subMinute()]);
        $this->voucher(['code' => 'SOON', 'starts_at' => now()->addDay()]);

        $this->assertRejected('Mã giảm giá không tồn tại hoặc đã ngừng áp dụng.', fn () => $this->vouchers->quote('NOPE', $this->items(100_000)));
        $this->assertRejected('Mã giảm giá không tồn tại hoặc đã ngừng áp dụng.', fn () => $this->vouchers->quote('OFF', $this->items(100_000)));
        $this->assertRejected('Mã giảm giá đã hết hạn.', fn () => $this->vouchers->quote('OLD', $this->items(100_000)));
        $this->assertRejected('Mã giảm giá chưa đến thời gian áp dụng.', fn () => $this->vouchers->quote('SOON', $this->items(100_000)));
    }

    public function test_redeem_fails_when_the_last_use_was_taken_in_the_meantime(): void
    {
        $voucher = $this->voucher(['usage_limit' => 1]);
        $quote = $this->vouchers->quote('SALE', $this->items(100_000));

        // Người khác vừa dùng lượt cuối sau khi mình đã báo giá
        Voucher::whereKey($voucher->id)->update(['used_count' => 1]);

        $this->assertRejected('Mã giảm giá đã hết lượt sử dụng.', fn () => $this->vouchers->redeem($quote, $this->occupy($this->tuan, '2026-10-05 10:00', 30)));
        $this->assertRejected('Mã giảm giá đã hết lượt sử dụng.', fn () => $this->vouchers->quote('SALE', $this->items(100_000)));
    }

    public function test_per_customer_limit_counts_only_unreleased_usages(): void
    {
        $voucher = $this->voucher(['usage_limit_per_customer' => 1]);
        $booking = $this->occupy($this->tuan, '2026-10-05 10:00', 30);
        $customer = $booking->customer;

        $usage = VoucherUsage::create(['voucher_id' => $voucher->id, 'booking_id' => $booking->id, 'customer_id' => $customer->id, 'discount_amount' => 10_000]);

        $this->assertRejected('Bạn đã dùng hết số lần cho phép của mã giảm giá này.', fn () => $this->vouchers->quote('SALE', $this->items(100_000), $customer));

        $usage->update(['released_at' => now()]);
        $this->assertSame(10_000, $this->vouchers->quote('SALE', $this->items(100_000), $customer)->discount);
    }

    public function test_first_booking_only_ignores_cancelled_bookings(): void
    {
        $this->voucher(['first_booking_only' => true]);
        $newcomer = Customer::create(['name' => 'Khách mới', 'phone' => '0911111111']);

        $this->assertSame(10_000, $this->vouchers->quote('SALE', $this->items(100_000), $newcomer)->discount);

        $booking = $this->occupy($this->tuan, '2026-10-05 10:00', 30, BookingStatus::Cancelled);
        $this->assertSame(10_000, $this->vouchers->quote('SALE', $this->items(100_000), $booking->customer)->discount);

        $booking->update(['status' => BookingStatus::Completed]);
        $this->assertRejected('Mã giảm giá chỉ áp dụng cho lần đặt lịch đầu tiên.', fn () => $this->vouchers->quote('SALE', $this->items(100_000), $booking->customer));
    }

    public function test_redeem_and_release_keep_used_count_in_sync(): void
    {
        $voucher = $this->voucher(['usage_limit' => 5]);
        $booking = $this->occupy($this->tuan, '2026-10-05 10:00', 30);

        $this->vouchers->redeem($this->vouchers->quote('SALE', $this->items(100_000)), $booking);
        $this->assertSame(1, $voucher->fresh()->used_count);

        $this->vouchers->release($booking);
        $this->vouchers->release($booking); // gọi lại không trừ thêm

        $this->assertSame(0, $voucher->fresh()->used_count);
        $this->assertNotNull($booking->voucherUsage->released_at);
    }
}
