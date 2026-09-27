<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Filament\Widgets\Revenue\RevenueByPaymentChart;
use App\Filament\Widgets\Revenue\RevenueByServiceChart;
use App\Filament\Widgets\Revenue\RevenueBySourceChart;
use App\Filament\Widgets\Revenue\RevenueByStaffChart;
use App\Filament\Widgets\Revenue\RevenueOverview;
use App\Filament\Widgets\Revenue\RevenueTable;
use App\Filament\Widgets\Revenue\RevenueTimelineChart;
use App\Services\Reports\RevenueReport;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Báo cáo doanh thu theo kỳ: tổng quan, theo ngày, theo thợ / dịch vụ / nguồn / hình thức thanh toán */
class Revenue extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'revenue';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Reports;

    protected static ?string $title = 'Doanh thu';

    protected static ?string $navigationLabel = 'Doanh thu';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->role->canManageCatalog();
    }

    public function getColumns(): int|array
    {
        return ['md' => 2];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('period')
                ->label('Kỳ báo cáo')
                ->options(RevenueReport::PERIODS)
                ->default('30d')
                ->selectablePlaceholder(false)
                ->live(),
            DatePicker::make('from')
                ->label('Từ ngày')
                ->maxDate(today())
                ->visible(fn (Get $get) => $get('period') === 'custom'),
            DatePicker::make('until')
                ->label('Đến ngày')
                ->maxDate(today())
                ->visible(fn (Get $get) => $get('period') === 'custom'),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            RevenueOverview::class,
            RevenueTimelineChart::class,
            RevenueByStaffChart::class,
            RevenueByServiceChart::class,
            RevenueBySourceChart::class,
            RevenueByPaymentChart::class,
            RevenueTable::class,
        ];
    }
}
