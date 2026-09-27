@use('App\Enums\BookingStatus')
<x-filament-panels::page>
    {{-- CSS riêng của trang: Tailwind build của Filament không chứa class tùy ý --}}
    <style>
        .sb-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .sb-toolbar .sb-spacer { flex: 1; }
        .sb-summary { font-size: .875rem; color: rgb(107 114 128); }
        .sb-alert { padding: .75rem 1rem; border-radius: .5rem; background: #fef3c7; color: #92400e; font-size: .875rem; }
        .sb-wrap { overflow-x: auto; border-radius: .75rem; border: 1px solid rgb(229 231 235); background: #fff; }
        .sb-grid { display: grid; min-width: 100%; }
        .sb-head { position: sticky; top: 0; z-index: 3; padding: .5rem; border-bottom: 1px solid rgb(229 231 235); border-left: 1px solid rgb(243 244 246); background: #fff; font-size: .875rem; font-weight: 600; text-align: center; }
        .sb-head small { display: block; font-weight: 400; color: rgb(107 114 128); }
        .sb-col { position: relative; border-left: 1px solid rgb(243 244 246); }
        .sb-hour { border-top: 1px solid rgb(243 244 246); box-sizing: border-box; }
        .sb-label { position: relative; font-size: .75rem; color: rgb(107 114 128); text-align: right; padding-right: .5rem; }
        .sb-label span { position: relative; top: -.55rem; }
        .sb-label div:first-child span { top: .1rem; }
        .sb-off { position: absolute; left: 0; right: 0; background: repeating-linear-gradient(135deg, rgb(243 244 246), rgb(243 244 246) 6px, rgb(249 250 251) 6px, rgb(249 250 251) 12px); font-size: .7rem; color: rgb(156 163 175); padding: .25rem; }
        .sb-booking { position: absolute; left: 3px; right: 3px; overflow: hidden; border-radius: .375rem; border-left: 4px solid; padding: .125rem .375rem; font-size: .75rem; line-height: 1.25; text-decoration: none; color: rgb(17 24 39); box-shadow: 0 1px 2px rgb(0 0 0 / .08); }
        .sb-booking:hover { z-index: 2; box-shadow: 0 4px 10px rgb(0 0 0 / .15); }
        .sb-booking b { font-weight: 600; }
        .sb-booking .sb-muted { color: rgb(75 85 99); }
        .sb-pending { background: #fef3c7; border-color: #f59e0b; }
        .sb-confirmed { background: #dbeafe; border-color: #3b82f6; }
        .sb-in_progress { background: #ede9fe; border-color: #8b5cf6; }
        .sb-completed { background: #dcfce7; border-color: #22c55e; }
        .sb-no_show, .sb-cancelled, .sb-rejected { background: #f3f4f6; border-color: #9ca3af; opacity: .7; text-decoration: line-through; }
        .sb-now { position: absolute; left: 0; right: 0; z-index: 2; border-top: 2px solid #ef4444; pointer-events: none; }
        .sb-legend { display: flex; flex-wrap: wrap; gap: .75rem; font-size: .75rem; color: rgb(107 114 128); }
        .sb-legend i { display: inline-block; width: 1rem; height: .875rem; border-radius: .2rem; border-left: 4px solid; margin-right: .3rem; vertical-align: middle; text-decoration: none; opacity: 1; }
        .dark .sb-wrap, .dark .sb-head { background: rgb(24 24 27); border-color: rgb(63 63 70); }
        .dark .sb-col, .dark .sb-hour, .dark .sb-head { border-color: rgb(39 39 42); }
        .dark .sb-off { background: repeating-linear-gradient(135deg, rgb(39 39 42), rgb(39 39 42) 6px, rgb(32 32 36) 6px, rgb(32 32 36) 12px); }
        .dark .sb-booking { color: rgb(17 24 39); }
    </style>

    <div class="sb-toolbar">
        <x-filament::button color="gray" icon="heroicon-m-chevron-left" wire:click="previousDay" size="sm">Hôm trước</x-filament::button>
        <x-filament::button color="gray" wire:click="goToday" size="sm" :disabled="$day->isToday()">Hôm nay</x-filament::button>
        <x-filament::button color="gray" icon="heroicon-m-chevron-right" icon-position="after" wire:click="nextDay" size="sm">Hôm sau</x-filament::button>
        <x-filament::input.wrapper>
            <x-filament::input type="date" wire:model.live="date" />
        </x-filament::input.wrapper>
        <label class="sb-summary">
            <x-filament::input.checkbox wire:model.live="showCancelled" />
            Hiện lịch đã hủy / từ chối
        </label>
        <div class="sb-spacer"></div>
        <span class="sb-summary">{{ $total }} lịch hẹn @if ($pendingCount) · <b style="color:#d97706">{{ $pendingCount }} chờ duyệt</b> @endif</span>
    </div>

    @if ($closedDay || $isShopClosed)
        <div class="sb-alert">Tiệm nghỉ ngày này{{ $closedDay?->reason ? ': '.$closedDay->reason : '' }}. Không nhận lịch online.</div>
    @endif

    @if ($columns->isEmpty())
        <x-filament::section>Không có nhân viên làm việc ngày này.</x-filament::section>
    @else
        <div class="sb-wrap">
            <div class="sb-grid" style="grid-template-columns: 3.5rem repeat({{ $columns->count() }}, minmax(9rem, 1fr));">
                <div class="sb-head"></div>
                @foreach ($columns as $column)
                    <div class="sb-head">
                        {{ $column['staff']->name }}
                        <small>{{ $column['count'] }} lịch</small>
                    </div>
                @endforeach

                <div class="sb-label" style="height: {{ $gridHeight }}px">
                    @foreach ($hours as $hour)
                        <div style="height: {{ $hourHeight }}px"><span>{{ sprintf('%02d:00', $hour) }}</span></div>
                    @endforeach
                </div>

                @foreach ($columns as $column)
                    <div class="sb-col" style="height: {{ $gridHeight }}px">
                        @foreach ($hours as $hour)
                            <div class="sb-hour" style="height: {{ $hourHeight }}px"></div>
                        @endforeach

                        @foreach ($column['off'] as $off)
                            <div class="sb-off" style="top: {{ $off['top'] }}px; height: {{ $off['height'] }}px">{{ $off['label'] }}</div>
                        @endforeach

                        @foreach ($column['bookings'] as $block)
                            @php($booking = $block['booking'])
                            <a href="{{ $block['url'] }}" wire:navigate
                               class="sb-booking sb-{{ $booking->status->value }}"
                               style="top: {{ $block['top'] }}px; height: {{ $block['height'] }}px"
                               title="{{ $booking->code }} · {{ $booking->status->getLabel() }} · {{ $booking->customer->phone }}">
                                <b>{{ $booking->start_at->format('H:i') }}–{{ $booking->end_at->format('H:i') }}</b>
                                {{ $booking->customer->name }}
                                <div class="sb-muted">{{ $booking->items->pluck('service_name')->implode(', ') }}</div>
                            </a>
                        @endforeach

                        @if ($nowTop !== null)
                            <div class="sb-now" style="top: {{ $nowTop }}px"></div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="sb-legend">
            @foreach ([BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::InProgress, BookingStatus::Completed, BookingStatus::NoShow] as $status)
                <span><i class="sb-{{ $status->value }}"></i>{{ $status->getLabel() }}</span>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
