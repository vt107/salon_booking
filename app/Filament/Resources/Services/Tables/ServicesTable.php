<?php

namespace App\Filament\Resources\Services\Tables;

use App\Models\Service;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label('')->disk('public')->square()->size(40),
                TextColumn::make('name')
                    ->label('Dịch vụ')
                    ->searchable()
                    ->description(fn (Service $record) => $record->category?->name),
                TextColumn::make('price')
                    ->label('Giá')
                    ->sortable()
                    ->formatStateUsing(fn (Service $record) => $record->priceLabel()),
                TextColumn::make('duration_minutes')
                    ->label('Thời lượng')
                    ->sortable()
                    ->formatStateUsing(fn (Service $record) => $record->duration_minutes.' phút'
                        .($record->buffer_minutes ? " (+{$record->buffer_minutes}' dọn)" : '')),
                TextColumn::make('staff_count')->label('Số thợ')->counts('staff'),
                ToggleColumn::make('is_featured')->label('Nổi bật'),
                ToggleColumn::make('is_active')->label('Đang cung cấp'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                SelectFilter::make('category_id')->label('Nhóm dịch vụ')->relationship('category', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
