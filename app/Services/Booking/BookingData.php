<?php

namespace App\Services\Booking;

use App\Enums\BookingSource;
use App\Models\User;
use Illuminate\Support\Carbon;

final readonly class BookingData
{
    /**
     * @param  list<int>  $serviceIds  theo thứ tự làm
     * @param  int|null  $staffId  null = "Bất kỳ ai", hệ thống tự gán
     * @param  User|null  $createdBy  admin / nhân viên tạo hộ khách => booking được xác nhận luôn
     */
    public function __construct(
        public string $customerName,
        public string $customerPhone,
        public array $serviceIds,
        public Carbon $startAt,
        public ?int $staffId = null,
        public ?string $customerEmail = null,
        public ?string $customerNote = null,
        public ?string $voucherCode = null,
        public BookingSource $source = BookingSource::Web,
        public ?int $qrCodeId = null,
        public ?User $createdBy = null,
        public bool $allowOutsideWorkingHours = false,
    ) {}
}
