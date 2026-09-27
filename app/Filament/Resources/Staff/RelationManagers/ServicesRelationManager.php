<?php

namespace App\Filament\Resources\Staff\RelationManagers;

use App\Models\Service;
use App\Support\Money;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServicesRelationManager extends RelationManager
{
    protected static string $relationship = 'services';

    protected static ?string $title = 'Dịch vụ làm được';

    protected static ?string $modelLabel = 'dịch vụ';

    public function form(Schema $schema): Schema
    {
        return $schema->components(self::pivotFields());
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->label('Dịch vụ'),
                TextColumn::make('price')
                    ->label('Giá')
                    ->formatStateUsing(fn (Service $record) => $record->pivot->custom_price !== null
                        ? Money::format($record->pivot->custom_price).' (riêng)'
                        : Money::format($record->price)),
                TextColumn::make('duration_minutes')
                    ->label('Thời lượng')
                    ->formatStateUsing(fn (Service $record) => $record->pivot->custom_duration_minutes !== null
                        ? $record->pivot->custom_duration_minutes.' phút (riêng)'
                        : $record->duration_minutes.' phút'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Thêm dịch vụ')
                    ->multiple()
                    ->preloadRecordSelect()
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Dịch vụ'),
                        ...self::pivotFields(),
                    ]),
            ])
            ->recordActions([
                EditAction::make()->label('Giá riêng'),
                DetachAction::make()->label('Bỏ'),
            ])
            ->toolbarActions([
                DetachBulkAction::make(),
            ]);
    }

    /** @return list<TextInput> */
    private static function pivotFields(): array
    {
        return [
            TextInput::make('custom_price')
                ->label('Giá riêng của thợ')
                ->helperText('Để trống = dùng giá mặc định của dịch vụ.')
                ->integer()
                ->minValue(0)
                ->step(1000)
                ->suffix('đ'),
            TextInput::make('custom_duration_minutes')
                ->label('Thời lượng riêng')
                ->helperText('Để trống = dùng thời lượng mặc định.')
                ->integer()
                ->minValue(5)
                ->suffix('phút'),
        ];
    }
}
