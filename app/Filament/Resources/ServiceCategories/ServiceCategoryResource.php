<?php

namespace App\Filament\Resources\ServiceCategories;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ServiceCategories\Pages\ManageServiceCategories;
use App\Models\ServiceCategory;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use UnitEnum;

class ServiceCategoryResource extends Resource
{
    protected static ?string $model = ServiceCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Catalog;

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'nhóm dịch vụ';

    protected static ?string $pluralModelLabel = 'Nhóm dịch vụ';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên nhóm')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')
                ->label('Đường dẫn')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Textarea::make('description')
                ->label('Mô tả')
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Hiển thị trên website')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên nhóm')->searchable(),
                TextColumn::make('services_count')->label('Số dịch vụ')->counts('services'),
                ToggleColumn::make('is_active')->label('Hiển thị'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (ServiceCategory $record) => ! $record->services()->withTrashed()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServiceCategories::route('/'),
        ];
    }
}
