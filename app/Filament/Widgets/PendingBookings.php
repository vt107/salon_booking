<?php

namespace App\Filament\Widgets;

use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\Actions\BookingActions;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Lịch đang chờ duyệt, sắp theo hạn duyệt: chủ tiệm duyệt ngay trên dashboard */
class PendingBookings extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected static ?string $heading = 'Lịch chờ duyệt';

    protected ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Booking::with(['customer', 'staff', 'items'])
                ->where('status', BookingStatus::Pending)
                ->orderBy('approval_deadline_at'))
            ->columns([
                TextColumn::make('approval_deadline_at')
                    ->label('Hạn duyệt')
                    ->dateTime('H:i d/m')
                    ->description(fn (Booking $record) => $record->approval_deadline_at?->diffForHumans())
                    ->color(fn (Booking $record) => $record->approval_deadline_at?->lt(now()->addMinutes(15)) ? 'danger' : 'warning'),
                TextColumn::make('start_at')->label('Giờ hẹn')->dateTime('H:i d/m/Y'),
                TextColumn::make('customer.name')
                    ->label('Khách')
                    ->description(fn (Booking $record) => $record->customer->phone
                        .($record->customer->no_show_count ? " · {$record->customer->no_show_count} lần không đến" : '')),
                TextColumn::make('services')
                    ->label('Dịch vụ')
                    ->state(fn (Booking $record) => $record->items->pluck('service_name')->implode(', '))
                    ->wrap(),
                TextColumn::make('staff.name')->label('Thợ'),
                TextColumn::make('total')->label('Tổng')->formatStateUsing(fn (int $state) => Money::format($state)),
            ])
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                BookingActions::confirm()->button(),
                BookingActions::reject()->button(),
            ])
            ->emptyStateHeading('Không có lịch chờ duyệt')
            ->emptyStateIcon('heroicon-o-check-badge')
            ->paginated([5, 10, 25]);
    }
}
