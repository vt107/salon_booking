<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Khách hàng')
                    ->searchable()
                    ->description(fn (Customer $record) => $record->email),
                TextColumn::make('phone')
                    ->label('Điện thoại')
                    ->searchable(query: fn (Builder $query, string $search) => $query->where('phone', 'like', '%'.preg_replace('/\D/', '', $search).'%'))
                    ->copyable(),
                TextColumn::make('total_visits')->label('Số lần đến')->sortable(),
                TextColumn::make('total_spent')
                    ->label('Tổng chi tiêu')
                    ->formatStateUsing(fn (int $state) => Money::format($state))
                    ->sortable(),
                TextColumn::make('no_show_count')
                    ->label('Không đến')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'danger' : 'gray')
                    ->sortable(),
                TextColumn::make('last_visit_at')->label('Lần gần nhất')->date('d/m/Y')->sortable(),
                IconColumn::make('is_blocked')->label('Chặn')->boolean()->trueColor('danger')->falseIcon(null),
            ])
            ->defaultSort('last_visit_at', 'desc')
            ->filters([
                TernaryFilter::make('is_blocked')->label('Bị chặn đặt online'),
                Filter::make('no_show')
                    ->label('Từng không đến')
                    ->query(fn (Builder $query) => $query->where('no_show_count', '>', 0)),
                Filter::make('birthday_this_month')
                    ->label('Sinh nhật trong tháng')
                    ->query(fn (Builder $query) => $query->whereMonth('birthday', now()->month)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
