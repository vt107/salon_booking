<?php

namespace App\Providers;

use Filament\Resources\Resource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Filament mặc định viết hoa mọi chữ đầu ("Danh Sách Lịch Hẹn"), không hợp tiếng Việt
        Resource::titleCaseModelLabel(false);
    }
}
