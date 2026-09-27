<?php

namespace App\Filament\Resources\QrCodes;

use App\Filament\NavigationGroup;
use App\Filament\Resources\QrCodes\Pages\ManageQrCodes;
use App\Filament\Resources\QrCodes\Schemas\QrCodeForm;
use App\Filament\Resources\QrCodes\Tables\QrCodesTable;
use App\Models\QrCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class QrCodeResource extends Resource
{
    protected static ?string $model = QrCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Marketing;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'mã QR';

    protected static ?string $pluralModelLabel = 'Mã QR đặt lịch';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return QrCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QrCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageQrCodes::route('/'),
        ];
    }
}
