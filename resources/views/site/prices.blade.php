<x-layouts.site page="prices">
    <section class="mx-auto max-w-3xl px-5 pt-16">
        <p class="eyebrow text-center">Bảng giá</p>
        <h1 class="font-display mt-4 text-center text-5xl font-medium tracking-tight md:text-6xl">Thực đơn dịch vụ</h1>
        <p class="mx-auto mt-5 max-w-md text-center leading-relaxed text-ink-soft">Giá niêm yết đã gồm công và sản phẩm tiêu chuẩn. Dịch vụ có khoảng giá sẽ được tư vấn cụ thể tại tiệm.</p>

        @if ($categories->count() > 1)
            <nav class="mt-10 flex flex-wrap justify-center gap-2">
                @foreach ($categories as $category)
                    <a href="#{{ $category->slug }}" class="rounded-full border border-ink/10 px-4 py-1.5 text-sm text-ink-soft transition hover:border-clay hover:text-clay">{{ $category->name }}</a>
                @endforeach
            </nav>
        @endif

        <div class="mt-14 rounded-[2rem] border border-ink/10 bg-paper px-6 py-4 shadow-[0_30px_60px_-40px_rgba(31,25,22,0.3)] md:px-12">
            @foreach ($categories as $category)
                <section id="{{ $category->slug }}" class="scroll-mt-28 border-b border-ink/10 py-10 last:border-b-0">
                    <h2 class="font-display text-center text-3xl italic text-clay">{{ $category->name }}</h2>
                    @if ($category->description)
                        <p class="mt-2 text-center text-sm text-ink-faint">{{ $category->description }}</p>
                    @endif
                    <ul class="mt-8 space-y-7">
                        @foreach ($category->services as $service)
                            <li class="group">
                                <div class="flex items-baseline">
                                    <h3 class="font-display text-xl">{{ $service->name }}</h3>
                                    <span class="leader"></span>
                                    <span class="whitespace-nowrap tabular-nums">{{ $service->priceLabel() }}</span>
                                </div>
                                <div class="mt-1 flex items-start justify-between gap-6">
                                    <p class="text-sm leading-relaxed text-ink-soft">
                                        <span class="text-ink-faint">{{ $service->duration_minutes }} phút</span>
                                        @if ($service->short_description) · {{ $service->short_description }} @endif
                                    </p>
                                    <a href="{{ route('booking.create', ['service' => $service->id]) }}" class="shrink-0 text-sm text-clay underline decoration-clay/30 underline-offset-4 hover:decoration-clay" wire:navigate>Đặt</a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
        <div class="mt-12 text-center">
            <a href="{{ route('booking.create') }}" class="btn-primary px-8 py-3.5 text-base" wire:navigate>Đặt lịch ngay</a>
        </div>
    </section>
</x-layouts.site>
