@props(['title' => null, 'description' => null])
@inject('shop', 'App\Support\ShopInfo')
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f6f1ea">
    <title>{{ $title ? $title.' · '.$shop->name() : $shop->name().' · Đặt lịch online' }}</title>
    <meta name="description" content="{{ $description ?? 'Đặt lịch tại '.$shop->name().' chỉ trong một phút: chọn dịch vụ, giờ và người thợ bạn thích.' }}">
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="flex min-h-dvh flex-col">
    <header class="sticky top-0 z-30 border-b border-ink/5 bg-cream/85 backdrop-blur-md">
        <div class="mx-auto flex h-16 max-w-6xl items-center gap-6 px-5">
            <a href="{{ route('home') }}" class="flex items-center gap-3" wire:navigate>
                @if ($logo = $shop->logoUrl())
                    <img src="{{ $logo }}" alt="" class="size-9 rounded-full object-cover">
                @endif
                <span class="font-display text-xl font-semibold tracking-tight">{{ $shop->name() }}</span>
            </a>
            <nav class="ml-auto hidden items-center gap-7 text-sm text-ink-soft md:flex">
                <a href="{{ route('prices') }}" class="transition hover:text-ink @if (request()->routeIs('prices')) text-ink @endif" wire:navigate>Bảng giá</a>
                <a href="{{ route('team') }}" class="transition hover:text-ink @if (request()->routeIs('team')) text-ink @endif" wire:navigate>Đội ngũ</a>
                <a href="#lien-he" class="transition hover:text-ink">Liên hệ</a>
            </nav>
            <a href="{{ route('booking.create') }}" class="btn-primary ml-auto px-5 py-2.5 md:ml-0" wire:navigate>Đặt lịch</a>
        </div>
        <nav class="flex gap-6 border-t border-ink/5 px-5 py-2 text-sm text-ink-soft md:hidden">
            <a href="{{ route('prices') }}" wire:navigate>Bảng giá</a>
            <a href="{{ route('team') }}" wire:navigate>Đội ngũ</a>
            <a href="#lien-he">Liên hệ</a>
        </nav>
    </header>

    <main class="flex-1">
        {{ $slot }}
    </main>

    <footer id="lien-he" class="mt-24 bg-ink text-cream/80">
        <div class="mx-auto grid max-w-6xl gap-12 px-5 py-16 md:grid-cols-3">
            <div>
                <p class="font-display text-3xl text-cream">{{ $shop->name() }}</p>
                @if ($address = $shop->get('address'))
                    <p class="mt-4 leading-relaxed">{{ $address }}</p>
                @endif
                @if ($map = $shop->get('map_url'))
                    <a href="{{ $map }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm text-clay-soft underline underline-offset-4 hover:text-cream">Chỉ đường trên Google Maps →</a>
                @endif
            </div>
            <div>
                <p class="eyebrow text-clay-soft">Giờ mở cửa</p>
                <dl class="mt-4 space-y-2 text-sm">
                    @foreach ($shop->openingHours() as $row)
                        <div class="flex justify-between gap-4 border-b border-cream/10 pb-2">
                            <dt>{{ $row['days'] }}</dt>
                            <dd class="tabular-nums text-cream">{{ $row['hours'] }}</dd>
                        </div>
                    @endforeach
                </dl>
            </div>
            <div>
                <p class="eyebrow text-clay-soft">Liên hệ</p>
                <ul class="mt-4 space-y-2 text-sm">
                    @if ($phone = $shop->get('phone'))
                        <li>Hotline: <a href="tel:{{ $phone }}" class="text-cream hover:underline">{{ $phone }}</a></li>
                    @endif
                    @if ($email = $shop->get('email'))
                        <li>Email: <a href="mailto:{{ $email }}" class="text-cream hover:underline">{{ $email }}</a></li>
                    @endif
                    @if ($zalo = $shop->zaloUrl())
                        <li><a href="{{ $zalo }}" target="_blank" rel="noopener" class="text-cream hover:underline">Nhắn Zalo</a></li>
                    @endif
                    @if ($facebook = $shop->get('facebook'))
                        <li><a href="{{ $facebook }}" target="_blank" rel="noopener" class="text-cream hover:underline">Facebook</a></li>
                    @endif
                </ul>
                <a href="{{ route('booking.create') }}" class="btn-accent mt-8" wire:navigate>Đặt lịch online</a>
            </div>
        </div>
        <p class="border-t border-cream/10 py-6 text-center text-xs text-cream/40">© {{ now()->year }} {{ $shop->name() }}</p>
    </footer>
</body>
</html>
