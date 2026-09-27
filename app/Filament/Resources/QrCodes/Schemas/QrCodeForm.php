<?php

namespace App\Filament\Resources\QrCodes\Schemas;

use App\Models\QrCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QrCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên gợi nhớ')
                ->placeholder('Poster quầy lễ tân, Fanpage, Danh thiếp anh Tuấn...')
                ->helperText('Dùng để biết khách đến từ đâu trong báo cáo.')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('code')
                ->label('Mã')
                ->default(fn () => QrCode::generateCode())
                ->required()
                ->alphaNum()
                ->minLength(3)
                ->maxLength(16)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (string $state) => strtoupper($state))
                ->helperText('Nằm trong link /q/{mã}. Đổi mã sau khi đã in thì mã cũ không dùng được nữa.'),
            Toggle::make('is_active')
                ->label('Đang dùng')
                ->helperText('Tắt thì khách quét vẫn vào trang đặt lịch nhưng không điền sẵn gì.')
                ->default(true)
                ->inline(false),
            Section::make('Điền sẵn khi khách quét')
                ->description('Không bắt buộc. Ví dụ: QR trên danh thiếp của thợ thì chọn thợ đó.')
                ->columns(3)
                ->columnSpanFull()
                ->schema([
                    Select::make('staff_id')->label('Nhân viên')->relationship('staff', 'name')->preload(),
                    Select::make('service_id')->label('Dịch vụ')->relationship('service', 'name')->searchable()->preload(),
                    Select::make('voucher_id')->label('Voucher')->relationship('voucher', 'code')->searchable()->preload(),
                ]),
        ]);
    }
}
