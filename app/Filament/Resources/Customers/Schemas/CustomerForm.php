<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\Gender;
use App\Models\Customer;
use App\Support\PhoneNumber;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Tên khách')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->required()
                        ->rule(fn (?Customer $record) => function (string $attribute, $value, Closure $fail) use ($record) {
                            if (! PhoneNumber::isValid((string) $value)) {
                                $fail('Số điện thoại không hợp lệ.');

                                return;
                            }

                            $taken = Customer::where('phone', PhoneNumber::normalize($value))
                                ->when($record, fn ($q) => $q->whereKeyNot($record->id))
                                ->exists();

                            if ($taken) {
                                $fail('Số điện thoại này đã thuộc về khách khác.');
                            }
                        }),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->maxLength(255),
                    Select::make('gender')
                        ->label('Giới tính')
                        ->options(Gender::class),
                    DatePicker::make('birthday')
                        ->label('Sinh nhật')
                        ->maxDate(today()),
                    Toggle::make('is_blocked')
                        ->label('Chặn đặt lịch online')
                        ->helperText('Khách vẫn đặt được qua tiệm (gọi điện, đến trực tiếp).')
                        ->visible(fn (?Customer $record) => auth()->user()->can('block', $record ?? Customer::class))
                        ->inline(false),
                    Textarea::make('note')
                        ->label('Ghi chú về khách')
                        ->placeholder('Dị ứng, sở thích, thợ quen...')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
