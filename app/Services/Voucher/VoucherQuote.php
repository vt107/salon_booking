<?php

namespace App\Services\Voucher;

use App\Models\Voucher;

final readonly class VoucherQuote
{
    /**
     * @param  array<int, int>  $allocations  vị trí dòng dịch vụ => số tiền giảm
     */
    public function __construct(
        public Voucher $voucher,
        public int $discount,
        public array $allocations,
    ) {}
}
