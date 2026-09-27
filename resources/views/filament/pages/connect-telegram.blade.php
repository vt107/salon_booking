<x-filament-panels::page>
    @if (! $botConfigured || ! $botUsername)
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="warning">
            <x-slot name="heading">Chưa cấu hình bot Telegram</x-slot>
            @if ($isAdmin)
                <p>Tạo bot với <b>@BotFather</b> trên Telegram (gõ <code>/newbot</code>), rồi dán token vào
                    <a href="{{ \App\Filament\Pages\Settings::getUrl() }}" style="text-decoration: underline">Cài đặt → Telegram</a>.</p>
            @else
                <p>Nhờ chủ tiệm (tài khoản Quản trị) cài bot ở Cài đặt → Telegram trước.</p>
            @endif
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Tài khoản của bạn</x-slot>
            @if ($user->hasTelegram())
                <p>Đã liên kết với Telegram <b>{{ $user->telegram_username ? '@'.$user->telegram_username : '#'.$user->telegram_user_id }}</b>.
                    Thông báo: <b>{{ $user->notify_telegram ? 'đang bật' : 'đang tắt' }}</b>.</p>
                <p style="margin-top:.5rem; opacity:.75">Bạn có thể bấm <b>Xác nhận / Từ chối</b> ngay trong tin nhắn của bot, kể cả trong group.</p>
            @else
                <p>Chưa liên kết. Bấm <b>Tạo link kết nối</b> rồi mở link trên điện thoại có Telegram và bấm <b>Start</b>.</p>
            @endif

            @if ($linkUrl)
                <div style="margin-top:1rem; padding:1rem; border-radius:.75rem; background: rgb(254 243 199); color: rgb(120 53 15)">
                    Link kết nối (hết hạn sau 15 phút, chỉ dùng một lần):<br>
                    <a href="{{ $linkUrl }}" target="_blank" rel="noopener" style="text-decoration: underline; word-break: break-all">{{ $linkUrl }}</a>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Ai đang nhận thông báo</x-slot>
            @if ($groupChatId)
                <p>Thông báo gửi vào <b>group</b> có Chat ID <code>{{ $groupChatId }}</code>. Mọi admin / quản lý đã liên kết đều bấm duyệt được trong group.</p>
            @else
                <p>Thông báo gửi riêng cho <b>{{ $recipients }}</b> admin / quản lý đã liên kết và đang bật thông báo.</p>
                <p style="margin-top:.5rem; opacity:.75">Muốn cả tiệm cùng thấy: thêm @{{ $botUsername }} vào group, gõ <code>/chatid</code> trong group rồi dán số đó vào Cài đặt → Telegram.</p>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
