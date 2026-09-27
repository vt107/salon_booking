<?php

namespace App\Filament\Pages;

use App\Filament\NavigationGroup;
use App\Models\Setting;
use App\Services\Telegram\AdminNotifier;
use App\Services\Telegram\TelegramClient;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Admin / quản lý liên kết tài khoản Telegram của mình để nhận thông báo và bấm duyệt lịch.
 */
class ConnectTelegram extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Kết nối Telegram';

    protected string $view = 'filament.pages.connect-telegram';

    /** Link mời kết nối vừa tạo (hết hạn sau 15 phút) */
    public ?string $linkUrl = null;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->canApproveBookings();
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        return [
            Action::make('link')
                ->label($user->hasTelegram() ? 'Kết nối lại' : 'Tạo link kết nối')
                ->icon(Heroicon::OutlinedLink)
                ->visible(fn () => filled(config('services.telegram.bot_username')))
                ->action(function () use ($user) {
                    $token = Str::random(32);
                    $user->forceFill(['telegram_link_token' => $token, 'telegram_link_expires_at' => now()->addMinutes(15)])->save();
                    $this->linkUrl = 'https://t.me/'.config('services.telegram.bot_username').'?start='.$token;
                }),
            Action::make('toggle')
                ->label($user->notify_telegram ? 'Tạm tắt thông báo' : 'Bật lại thông báo')
                ->color('gray')
                ->visible(fn () => $user->hasTelegram())
                ->action(fn () => $user->update(['notify_telegram' => ! $user->notify_telegram])),
            Action::make('unlink')
                ->label('Hủy liên kết')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn () => $user->hasTelegram())
                ->action(function () use ($user) {
                    $user->forceFill(['telegram_user_id' => null, 'telegram_username' => null])->save();
                    Notification::make()->success()->title('Đã hủy liên kết Telegram')->send();
                }),
            Action::make('test')
                ->label('Gửi tin thử')
                ->color('gray')
                ->visible(fn () => app(AdminNotifier::class)->isEnabled())
                ->action(function () {
                    app(AdminNotifier::class)->broadcast('👋 Tin nhắn thử từ <b>'.e(Setting::get('shop.name', config('app.name'))).'</b>. Thông báo lịch hẹn sẽ đến đây.');
                    Notification::make()->success()->title('Đã gửi tin thử')->send();
                }),
        ];
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'user' => auth()->user(),
            'botConfigured' => app(TelegramClient::class)->isConfigured(),
            'botUsername' => config('services.telegram.bot_username'),
            'groupChatId' => Setting::get('telegram.group_chat_id'),
            'recipients' => count(app(AdminNotifier::class)->chatIds()),
        ];
    }
}
