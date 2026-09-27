<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Họ tên')->searchable()->description(fn (User $record) => $record->email),
                TextColumn::make('role')->label('Vai trò')->badge(),
                IconColumn::make('telegram_user_id')
                    ->label('Telegram')
                    ->state(fn (User $record) => $record->hasTelegram())
                    ->boolean(),
                IconColumn::make('is_active')->label('Được đăng nhập')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
