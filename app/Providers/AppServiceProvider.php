<?php

namespace App\Providers;

use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramConfig;
use Filament\Resources\Resource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // bind (không singleton): đọc token mỗi lần dùng, admin đổi token có hiệu lực ngay
        $this->app->bind(TelegramClient::class, fn ($app) => new TelegramClient($app->make(TelegramConfig::class)->token()));
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
