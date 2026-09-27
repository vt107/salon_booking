<?php

namespace App\Filament\Resources\Bookings\Pages;

use App\Enums\BookingStatus;
use App\Filament\Resources\Bookings\BookingResource;
use App\Models\Booking;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tạo lịch hộ khách'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Chờ duyệt')
                ->badge(fn () => Booking::where('status', BookingStatus::Pending)->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('status', BookingStatus::Pending)
                    ->reorder('approval_deadline_at')),
            'today' => Tab::make('Hôm nay')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereBetween('start_at', [today(), today()->endOfDay()])
                    ->reorder('start_at')),
            'upcoming' => Tab::make('Sắp tới')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->blocking()
                    ->where('start_at', '>=', now())
                    ->reorder('start_at')),
            'all' => Tab::make('Tất cả'),
        ];
    }

    public function getDefaultActiveTab(): string
    {
        return Booking::where('status', BookingStatus::Pending)->exists() ? 'pending' : 'today';
    }
}
