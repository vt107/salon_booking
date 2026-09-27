<x-layouts.site page="team">
    <section class="mx-auto max-w-6xl px-5 pt-16">
        <p class="eyebrow">Đội ngũ</p>
        <h1 class="font-display mt-4 max-w-2xl text-5xl font-medium tracking-tight md:text-6xl">Những đôi tay <em class="text-clay">khéo léo</em> của tiệm</h1>

        <div class="mt-14 grid gap-6 md:grid-cols-2">
            @foreach ($staff as $member)
                <article class="flex flex-col rounded-3xl border border-ink/10 bg-paper p-8">
                    <div class="flex items-center gap-5">
                        <x-staff-avatar :staff="$member" size="size-20" class="text-2xl" />
                        <div>
                            <h2 class="font-display text-2xl">{{ $member->name }}</h2>
                            <p class="text-sm text-clay">{{ $member->title }}</p>
                        </div>
                    </div>
                    @if ($member->bio)
                        <p class="mt-5 leading-relaxed text-ink-soft">{{ $member->bio }}</p>
                    @endif
                    <div class="mt-6 flex flex-wrap gap-2">
                        @foreach ($member->services as $service)
                            <span class="rounded-full bg-cream px-3 py-1 text-xs text-ink-soft">{{ $service->name }}</span>
                        @endforeach
                    </div>
                    <a href="{{ route('booking.create', ['staff' => $member->id]) }}" class="btn-ghost mt-8 self-start" wire:navigate>Đặt lịch với {{ $member->name }}</a>
                </article>
            @endforeach
        </div>
    </section>
</x-layouts.site>
