<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Lịch sử đặt lịch';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['items', 'staff']))
            ->columns([
                TextColumn::make('code')->label('Mã')->weight('bold'),
                TextColumn::make('start_at')->label('Thời gian')->dateTime('H:i d/m/Y')->sortable(),
                TextColumn::make('services')
                    ->label('Dịch vụ')
                    ->state(fn (Booking $record) => $record->items->pluck('service_name')->implode(', '))
                    ->wrap(),
                TextColumn::make('staff.name')->label('Nhân viên'),
                TextColumn::make('total')->label('Tổng tiền')->formatStateUsing(fn (int $state) => Money::format($state)),
                TextColumn::make('status')->label('Trạng thái')->badge(),
            ])
            ->defaultSort('start_at', 'desc')
            ->recordUrl(fn (Booking $record) => BookingResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('book')
                    ->label('Tạo lịch cho khách này')
                    ->url(fn () => BookingResource::getUrl('create', ['phone' => $this->getOwnerRecord()->phone])),
            ]);
    }
}
