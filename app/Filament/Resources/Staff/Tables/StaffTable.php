<?php

namespace App\Filament\Resources\Staff\Tables;

use App\Models\Staff;
use App\Support\Weekday;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class StaffTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('schedules')->withCount('services'))
            ->columns([
                ImageColumn::make('avatar')->label('')->disk('public')->circular()->size(40),
                TextColumn::make('name')
                    ->label('Nhân viên')
                    ->searchable()
                    ->description(fn (Staff $record) => $record->title),
                TextColumn::make('services_count')->label('Số dịch vụ'),
                TextColumn::make('days_off')
                    ->label('Ngày nghỉ')
                    ->state(fn (Staff $record) => collect(Weekday::options())
                        ->except($record->schedules->pluck('day_of_week')->unique()->all())
                        ->implode(', ') ?: '—'),
                ToggleColumn::make('is_bookable')->label('Nhận lịch online'),
                ToggleColumn::make('is_active')->label('Đang làm'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ]);
    }
}
