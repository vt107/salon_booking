<?php

namespace App\Filament\Resources\Staff\RelationManagers;

use App\Models\Booking;
use App\Models\StaffTimeOff;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TimeOffsRelationManager extends RelationManager
{
    protected static string $relationship = 'timeOffs';

    protected static ?string $title = 'Lịch nghỉ';

    protected static ?string $modelLabel = 'lịch nghỉ';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('start_at')
                ->label('Từ')
                ->seconds(false)
                ->minutesStep(15)
                ->required(),
            DateTimePicker::make('end_at')
                ->label('Đến')
                ->seconds(false)
                ->minutesStep(15)
                ->required()
                ->after('start_at'),
            TextInput::make('reason')
                ->label('Lý do')
                ->maxLength(255)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('start_at')->label('Từ')->dateTime('H:i d/m/Y')->sortable(),
                TextColumn::make('end_at')->label('Đến')->dateTime('H:i d/m/Y'),
                TextColumn::make('reason')->label('Lý do'),
                TextColumn::make('creator.name')->label('Người tạo'),
            ])
            ->defaultSort('start_at', 'desc')
            ->headerActions([
                CreateAction::make()
                    ->label('Thêm lịch nghỉ')
                    ->mutateDataUsing(fn (array $data) => [...$data, 'created_by' => auth()->id()])
                    ->after(fn (StaffTimeOff $record) => $this->warnAboutBookings($record)),
            ])
            ->recordActions([
                EditAction::make()->after(fn (StaffTimeOff $record) => $this->warnAboutBookings($record)),
                DeleteAction::make(),
            ]);
    }

    /** Lịch nghỉ không tự hủy booking đã có: nhắc admin xử lý */
    private function warnAboutBookings(StaffTimeOff $timeOff): void
    {
        $count = Booking::where('staff_id', $timeOff->staff_id)
            ->blocking()
            ->overlapping($timeOff->start_at, $timeOff->end_at)
            ->count();

        if ($count > 0) {
            Notification::make()
                ->warning()
                ->title("Có {$count} lịch hẹn trong thời gian nghỉ này")
                ->body('Hãy đổi thợ / đổi giờ hoặc hủy các lịch đó trong mục Lịch hẹn.')
                ->persistent()
                ->send();
        }
    }
}
