<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Enums\BookingSource;
use App\Filament\Resources\Bookings\BookingResource;
use App\Services\Booking\BookingData;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Voucher\VoucherException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected static ?string $title = 'Tạo lịch hẹn hộ khách';

    protected static bool $canCreateAnother = false;

    protected function handleRecordCreation(array $data): Model
    {
        $time = $data['outside_hours'] ? $data['manual_time'] : $data['time'];

        try {
            return app(BookingService::class)->create(new BookingData(
                customerName: $data['customer_name'],
                customerPhone: $data['customer_phone'],
                serviceIds: array_map('intval', $data['service_ids']),
                startAt: Carbon::parse($data['date'].' '.$time),
                staffId: $data['staff_id'] ? (int) $data['staff_id'] : null,
                customerEmail: $data['customer_email'] ?: null,
                customerNote: $data['customer_note'] ?: null,
                voucherCode: $data['voucher_code'] ?: null,
                source: BookingSource::from($data['source']),
                createdBy: auth()->user(),
                allowOutsideWorkingHours: (bool) $data['outside_hours'],
            ));
        } catch (BookingException|VoucherException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Đã tạo lịch hẹn';
    }

    protected function getRedirectUrl(): string
    {
        return BookingResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
