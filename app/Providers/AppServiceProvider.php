<?php

namespace App\Providers;

use App\Services\Telegram\TelegramClient;
use Filament\Resources\Resource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // bind (không singleton): đọc config lúc dùng, test đổi token được
        $this->app->bind(TelegramClient::class, fn () => new TelegramClient(config('services.telegram.bot_token')));
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
