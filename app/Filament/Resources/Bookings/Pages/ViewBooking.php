<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Filament\Resources\Bookings\Actions\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use Filament\Resources\Pages\ViewRecord;

class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    public function getTitle(): string
    {
        return "Lịch hẹn {$this->getRecord()->code}";
    }

    protected function getHeaderActions(): array
    {
        // Sau mỗi thao tác, nạp lại booking để trạng thái / nút hiển thị đúng
        return array_map(
            fn ($action) => $action->after(fn () => $this->getRecord()->refresh()),
            BookingActions::all(),
        );
    }
}
