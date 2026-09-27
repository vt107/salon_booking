<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Tài khoản')
                    ->schema([
                        TextInput::make('name')->label('Họ tên')->required()->maxLength(255),
                        TextInput::make('email')->label('Email đăng nhập')->email()->required()->unique(ignoreRecord: true),
                        TextInput::make('phone')->label('Số điện thoại')->tel()->maxLength(20),
                        TextInput::make('password')
                            ->label(fn (string $operation) => $operation === 'create' ? 'Mật khẩu' : 'Đổi mật khẩu')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->required(fn (string $operation) => $operation === 'create')
                            ->dehydrated(fn (?string $state) => filled($state))
                            ->helperText(fn (string $operation) => $operation === 'edit' ? 'Để trống nếu không đổi.' : null),
                    ]),
                Section::make('Phân quyền')
                    ->schema([
                        Radio::make('role')
                            ->label('Vai trò')
                            ->options(UserRole::class)
                            ->descriptions([
                                UserRole::Admin->value => 'Toàn quyền, kể cả cài đặt và tài khoản.',
                                UserRole::Manager->value => 'Duyệt lịch, quản lý dịch vụ, nhân viên, voucher.',
                                UserRole::Staff->value => 'Xem lịch, tạo lịch hộ khách, check-in, thu tiền. Không duyệt lịch.',
                            ])
                            ->default(UserRole::Staff)
                            ->required()
                            ->disabled(fn (?User $record) => $record?->is(auth()->user())),
                        Toggle::make('is_active')
                            ->label('Được đăng nhập')
                            ->default(true)
                            ->disabled(fn (?User $record) => $record?->is(auth()->user())),
                        Toggle::make('notify_telegram')
                            ->label('Nhận thông báo Telegram')
                            ->default(true),
                        TextInput::make('telegram_username')
                            ->label('Telegram')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Chưa liên kết')
                            ->visibleOn('edit'),
                    ]),
            ]);
    }
}
