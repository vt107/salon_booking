<?php

namespace App\Filament\Widgets\Revenue;

use App\Support\Money;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Bảng số liệu của biểu đồ theo ngày / tháng (đọc số chính xác, không phụ thuộc màu) */
class RevenueTable extends TableWidget
{
    use ReadsRevenueFilters;

    protected static ?int $sort = 7;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $report = $this->report();

        return $table
            ->heading('Bảng số liệu '.($report->groupsByMonth() ? 'theo tháng' : 'theo ngày'))
            ->records(fn () => collect($report->timeline())->reverse()->keyBy('key')->all())
            ->columns([
                TextColumn::make('label')->label($report->groupsByMonth() ? 'Tháng' : 'Ngày'),
                TextColumn::make('count')->label('Lịch hoàn thành')->alignEnd(),
                TextColumn::make('revenue')->label('Doanh thu')->alignEnd()->formatStateUsing(fn (int $state) => Money::format($state)),
            ])
            // Tối đa ~62 dòng (theo ngày) hoặc 12 dòng (theo tháng)
            ->paginated(false);
    }
}
