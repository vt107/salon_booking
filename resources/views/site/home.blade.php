@inject('shop', 'App\Support\ShopInfo')
@inject('site', 'App\Support\SiteSettings')
<x-layouts.site page="home">
    {{-- Hero --}}
    <section class="relative overflow-hidden">
        <div class="pointer-events-none absolute -top-40 -right-40 size-[34rem] rounded-full bg-clay-soft/70 blur-3xl"></div>
        <div class="relative mx-auto grid max-w-6xl items-center gap-14 px-5 pt-16 pb-20 md:grid-cols-[1.15fr_1fr] md:pt-24">
            <div>
                <p class="eyebrow animate-rise">{{ $site->get('hero_eyebrow') }}</p>
                <h1 class="font-display font-display-soft mt-6 text-5xl leading-[1.02] font-medium tracking-tight animate-rise [animation-delay:80ms] sm:text-6xl md:text-7xl">
                    {{ $site->emphasize($site->get('hero_title')) }}
                </h1>
                <p class="mt-6 max-w-md text-lg leading-relaxed text-ink-soft animate-rise [animation-delay:160ms]">
                    {{ $site->get('hero_subtitle') }}
                </p>
                <div class="mt-9 flex flex-wrap gap-3 animate-rise [animation-delay:240ms]">
                    <a href="{{ route('booking.create') }}" class="btn-accent px-7 py-3.5 text-base" wire:navigate>{{ $site->get('hero_cta') }}</a>
                    <a href="{{ route('prices') }}" class="btn-ghost px-7 py-3.5 text-base" wire:navigate>Xem bảng giá</a>
                </div>
                @if ($today = $shop->todayHours())
                    <p class="mt-8 flex items-center gap-2 text-sm text-ink-soft animate-rise [animation-delay:320ms]">
                        <span class="relative flex size-2"><span class="absolute inline-flex size-full animate-ping rounded-full bg-sage opacity-60"></span><span class="relative inline-flex size-2 rounded-full bg-sage"></span></span>
                        Hôm nay mở cửa {{ $today }}
                    </p>
                @endif
            </div>

            {{-- "Tấm thẻ menu" nghiêng: dịch vụ nổi bật như trang thực đơn --}}
            @if ($featured->isNotEmpty())
                <div class="relative animate-rise [animation-delay:200ms]">
                    <div class="absolute inset-0 translate-x-4 translate-y-4 rotate-2 rounded-[2rem] bg-sand"></div>
                    <div class="relative -rotate-1 rounded-[2rem] border border-ink/10 bg-paper p-8 shadow-[0_30px_60px_-30px_rgba(31,25,22,0.35)] md:p-10">
                        <p class="text-center font-display text-sm tracking-[0.3em] text-ink-faint uppercase">Được chọn nhiều</p>
                        <ul class="mt-8 space-y-5">
                            @foreach ($featured->take(4) as $service)
                                <li>
                                    <div class="flex items-baseline">
                                        <span class="font-display text-xl">{{ $service->name }}</span>
                                        <span class="leader"></span>
                                        <span class="tabular-nums text-ink">{{ \App\Support\Money::format($service->price) }}</span>
                                    </div>
                                    <p class="mt-1 text-sm text-ink-faint">{{ $service->duration_minutes }} phút · {{ $service->category->name }}</p>
                                </li>
                            @endforeach
                        </ul>
                        <a href="{{ route('prices') }}" class="mt-8 block text-center text-sm text-clay underline decoration-clay/30 underline-offset-4 hover:decoration-clay" wire:navigate>Xem toàn bộ bảng giá</a>
                    </div>
                </div>
            @endif
        </div>
    </section>

    {{-- Ba bước --}}
    <section class="border-y border-ink/10 bg-paper">
        <div class="mx-auto grid max-w-6xl divide-y divide-ink/10 px-5 md:grid-cols-3 md:divide-x md:divide-y-0">
            @foreach ([
                ['Chọn dịch vụ', 'Một hoặc nhiều dịch vụ, làm liền mạch trong một lần hẹn.'],
                ['Chọn giờ & người thợ', 'Chỉ hiện những khung giờ còn trống thật sự.'],
                ['Nhận xác nhận', 'Tiệm xác nhận qua email. Đổi ý? Hủy chỉ với một chạm.'],
            ] as $i => [$title, $text])
                <div class="py-10 md:px-10 md:first:pl-0 md:last:pr-0">
                    <p class="font-display text-5xl text-clay/80 italic">0{{ $i + 1 }}</p>
                    <h3 class="mt-4 text-lg font-semibold">{{ $title }}</h3>
                    <p class="mt-2 leading-relaxed text-ink-soft">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Dịch vụ nổi bật --}}
    @if ($featured->isNotEmpty())
        <section class="mx-auto max-w-6xl px-5 pt-24">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="eyebrow">Dịch vụ</p>
                    <h2 class="font-display mt-3 text-4xl font-medium tracking-tight md:text-5xl">Những gì chúng tôi làm tốt nhất</h2>
                </div>
                <a href="{{ route('prices') }}" class="text-sm text-ink-soft underline underline-offset-4 hover:text-ink" wire:navigate>Tất cả dịch vụ →</a>
            </div>
            <div class="mt-12 grid gap-px overflow-hidden rounded-3xl border border-ink/10 bg-ink/10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($featured as $service)
                    <a href="{{ route('booking.create', ['service' => $service->id]) }}" class="group flex flex-col bg-paper p-8 transition hover:bg-cream" wire:navigate>
                        <span class="text-xs tracking-[0.2em] text-ink-faint uppercase">{{ $service->category->name }}</span>
                        <span class="font-display mt-4 text-2xl">{{ $service->name }}</span>
                        @if ($service->short_description)
                            <span class="mt-2 text-sm leading-relaxed text-ink-soft">{{ $service->short_description }}</span>
                        @endif
                        <span class="mt-auto flex items-center justify-between pt-8 text-sm">
                            <span class="text-ink-soft">{{ $service->duration_minutes }} phút · <span class="text-ink">{{ $service->priceLabel() }}</span></span>
                            <span class="text-clay opacity-0 transition group-hover:translate-x-1 group-hover:opacity-100">Đặt →</span>
                        </span>
                    </a>
                @endforeach
                {{-- Ô cuối dẫn tới bảng giá, cũng lấp chỗ trống của lưới --}}
                <a href="{{ route('prices') }}" class="group flex flex-col justify-between bg-ink p-8 text-cream transition hover:bg-clay" wire:navigate>
                    <span class="text-xs tracking-[0.2em] text-cream/50 uppercase">Bảng giá</span>
                    <span class="font-display mt-4 text-2xl">Xem toàn bộ thực đơn dịch vụ</span>
                    <span class="mt-8 text-sm text-cream/70 transition group-hover:translate-x-1">→</span>
                </a>
            </div>
        </section>
    @endif

    {{-- Đội ngũ --}}
    @if ($staff->isNotEmpty())
        <section class="mx-auto max-w-6xl px-5 pt-24">
            <p class="eyebrow">Đội ngũ</p>
            <h2 class="font-display mt-3 text-4xl font-medium tracking-tight md:text-5xl">Người thợ của bạn</h2>
            <div class="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($staff as $member)
                    <a href="{{ route('booking.create', ['staff' => $member->id]) }}" class="group text-center" wire:navigate>
                        <x-staff-avatar :staff="$member" size="size-28" class="mx-auto text-3xl ring-4 ring-paper transition group-hover:ring-clay-soft" />
                        <p class="font-display mt-5 text-xl">{{ $member->name }}</p>
                        <p class="text-sm text-ink-soft">{{ $member->title }}</p>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Kêu gọi đặt lịch --}}
    <section class="mx-auto max-w-6xl px-5 pt-24">
        <div class="relative overflow-hidden rounded-[2rem] bg-clay px-8 py-14 text-paper md:px-16">
            <div class="pointer-events-none absolute -right-16 -bottom-24 size-72 rounded-full border-[40px] border-paper/10"></div>
            <h2 class="font-display relative max-w-xl text-4xl leading-tight md:text-5xl">{{ $site->emphasize($site->get('cta_title')) }}</h2>
            <a href="{{ route('booking.create') }}" class="btn relative mt-8 bg-paper px-7 py-3.5 text-base text-clay-dark hover:bg-cream" wire:navigate>{{ $site->get('cta_button') }}</a>
        </div>
    </section>
</x-layouts.site>
