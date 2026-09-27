@php
    use App\Support\Money;
    $steps = ['Dịch vụ', 'Thời gian', 'Người thợ', 'Xác nhận'];
    $weekdays = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    $longWeekdays = ['Chủ nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
    $chosenDate = $date ? \Illuminate\Support\Carbon::parse($date) : null;
    $chosenStaff = $staffChoice === 'any' ? null : $this->staffOptions->firstWhere('id', (int) $staffChoice);
    $total = max(0, $subtotal - $discount);
    $canNext = match ($step) {
        1 => $serviceIds !== [],
        2 => $date && $time,
        3 => true,
        default => false,
    };
@endphp

<div class="mt-8 grid gap-10 pb-32 lg:grid-cols-[minmax(0,1fr)_22rem] lg:pb-0">
    {{-- min-w-0: không để hàng chọn ngày (cuộn ngang) kéo giãn cả trang --}}
    <div class="min-w-0">
        {{-- Thanh bước --}}
        <ol class="flex items-center gap-2 text-sm">
            @foreach ($steps as $i => $label)
                @php($n = $i + 1)
                <li class="flex items-center gap-2 @if (! $loop->last) flex-1 @endif">
                    <button type="button" wire:click="goTo({{ $n }})" @disabled($n >= $step)
                        class="flex items-center gap-2 whitespace-nowrap {{ $n === $step ? 'text-ink' : ($n < $step ? 'text-clay hover:underline' : 'text-ink-faint') }}">
                        <span class="flex size-7 items-center justify-center rounded-full text-xs font-semibold {{ $n === $step ? 'bg-ink text-cream' : ($n < $step ? 'bg-clay-soft text-clay-dark' : 'border border-ink/15') }}">
                            {{ $n < $step ? '✓' : $n }}
                        </span>
                        <span class="{{ $n === $step ? '' : 'hidden sm:inline' }}">{{ $label }}</span>
                    </button>
                    @if (! $loop->last)
                        <span class="h-px flex-1 bg-ink/10"></span>
                    @endif
                </li>
            @endforeach
        </ol>

        <div class="mt-10" wire:key="step-{{ $step }}">
            {{-- Bước 1: dịch vụ --}}
            @if ($step === 1)
                <h2 class="font-display text-2xl">Bạn muốn làm gì hôm nay?</h2>
                <p class="mt-1 text-sm text-ink-soft">Chọn một hoặc nhiều dịch vụ (tối đa {{ \App\Livewire\BookingWizard::MAX_SERVICES }}), làm theo thứ tự bạn chọn.</p>

                @if ($preferredStaff)
                    <div class="mt-6 flex items-center gap-4 rounded-2xl bg-clay-soft/60 p-4">
                        <x-staff-avatar :staff="$preferredStaff" size="size-11" class="text-base" />
                        <p class="flex-1 text-sm">Đặt với <b>{{ $preferredStaff->name }}</b>: chỉ hiện dịch vụ và giờ của {{ $preferredStaff->name }}.</p>
                        <button type="button" wire:click="clearPreferredStaff" class="text-sm text-clay-dark underline underline-offset-4">Chọn thợ khác</button>
                    </div>
                @endif

                <div class="mt-8 space-y-10">
                    @foreach ($this->categories as $category)
                        <div>
                            <h3 class="font-display text-lg text-clay italic">{{ $category->name }}</h3>
                            <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                @foreach ($category->services as $service)
                                    @php($position = array_search($service->id, $serviceIds, true))
                                    <button type="button" wire:click="toggleService({{ $service->id }})" wire:key="svc-{{ $service->id }}"
                                        class="flex items-start gap-3 rounded-2xl border p-4 text-left transition {{ $position !== false ? 'border-ink bg-paper shadow-sm' : 'border-ink/10 bg-paper/60 hover:border-ink/30' }}">
                                        <span class="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $position !== false ? 'bg-ink text-cream' : 'border border-ink/20' }}">
                                            {{ $position !== false ? $position + 1 : '' }}
                                        </span>
                                        <span class="flex-1">
                                            <span class="block font-medium">{{ $service->name }}</span>
                                            <span class="mt-0.5 block text-sm text-ink-soft">{{ $service->duration_minutes }} phút · {{ $service->priceLabel() }}</span>
                                        </span>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- Bước 2: ngày & giờ --}}
            @if ($step === 2)
                <h2 class="font-display text-2xl">Bạn muốn đến lúc nào?</h2>
                @if ($this->availableDates === [])
                    <p class="mt-6 rounded-2xl bg-paper p-6 text-ink-soft">Rất tiếc, hiện chưa còn khung giờ trống cho các dịch vụ này. Bạn thử bớt dịch vụ hoặc gọi cho tiệm nhé.</p>
                @else
                    <div class="-mx-5 mt-6 flex gap-2 overflow-x-auto px-5 pb-2 [scrollbar-width:none]">
                        @foreach ($this->availableDates as $d)
                            @php($c = \Illuminate\Support\Carbon::parse($d))
                            <button type="button" wire:click="selectDate('{{ $d }}')" wire:key="date-{{ $d }}" data-date="{{ $d }}"
                                class="flex w-16 shrink-0 flex-col items-center rounded-2xl border py-3 transition {{ $date === $d ? 'border-ink bg-ink text-cream' : 'border-ink/10 bg-paper hover:border-ink/30' }}">
                                <span class="text-xs {{ $date === $d ? 'text-cream/70' : 'text-ink-faint' }}">{{ $c->isToday() ? 'Nay' : ($c->isTomorrow() ? 'Mai' : $weekdays[$c->dayOfWeek]) }}</span>
                                <span class="font-display mt-1 text-2xl leading-none">{{ $c->day }}</span>
                                <span class="mt-1 text-[11px] {{ $date === $d ? 'text-cream/70' : 'text-ink-faint' }}">th {{ $c->month }}</span>
                            </button>
                        @endforeach
                    </div>

                    @error('time')
                        <p class="mt-4 rounded-xl bg-clay-soft px-4 py-3 text-sm text-clay-dark">{{ $message }}</p>
                    @enderror

                    @if ($date)
                        <div class="mt-8 space-y-6" wire:loading.class="opacity-50" wire:target="selectDate">
                            @php($groups = collect(array_keys($this->timeSlots))->groupBy(fn ($t) => (int) substr($t, 0, 2) < 12 ? 'Buổi sáng' : ((int) substr($t, 0, 2) < 17 ? 'Buổi chiều' : 'Buổi tối')))
                            @forelse ($groups as $label => $times)
                                <div>
                                    <p class="text-sm text-ink-soft">{{ $label }}</p>
                                    <div class="mt-2 grid grid-cols-4 gap-2 sm:grid-cols-6">
                                        @foreach ($times as $t)
                                            <button type="button" wire:click="selectTime('{{ $t }}')" wire:key="time-{{ $t }}" data-time="{{ $t }}"
                                                class="rounded-xl border py-2.5 text-sm tabular-nums transition {{ $time === $t ? 'border-ink bg-ink text-cream' : 'border-ink/10 bg-paper hover:border-ink/30' }}">{{ $t }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            @empty
                                <p class="text-ink-soft">Ngày này đã kín lịch, bạn chọn ngày khác nhé.</p>
                            @endforelse
                        </div>
                    @else
                        <p class="mt-6 text-sm text-ink-soft">Chọn một ngày để xem giờ còn trống.</p>
                    @endif
                @endif
            @endif

            {{-- Bước 3: người thợ --}}
            @if ($step === 3)
                <h2 class="font-display text-2xl">Ai sẽ làm cho bạn?</h2>
                <p class="mt-1 text-sm text-ink-soft">Những người đang rảnh lúc {{ $time }}, {{ $longWeekdays[$chosenDate->dayOfWeek] }} {{ $chosenDate->format('d/m') }}.</p>
                <div class="mt-6 grid gap-3 sm:grid-cols-2">
                    <button type="button" wire:click="selectStaff('any')"
                        class="flex items-center gap-4 rounded-2xl border p-4 text-left transition {{ $staffChoice === 'any' ? 'border-ink bg-paper shadow-sm' : 'border-ink/10 bg-paper/60 hover:border-ink/30' }}">
                        <span class="flex size-12 items-center justify-center rounded-full bg-sand font-display text-xl">✦</span>
                        <span>
                            <span class="block font-medium">Bất kỳ ai</span>
                            <span class="block text-sm text-ink-soft">Tiệm xếp người rảnh phù hợp</span>
                        </span>
                    </button>
                    @foreach ($this->staffOptions as $member)
                        @php($price = $this->selectedServices->sum(fn ($s) => $member->services->firstWhere('id', $s->id)?->pivot->custom_price ?? $s->price))
                        <button type="button" wire:click="selectStaff('{{ $member->id }}')" wire:key="staff-{{ $member->id }}"
                            class="flex items-center gap-4 rounded-2xl border p-4 text-left transition {{ $staffChoice === (string) $member->id ? 'border-ink bg-paper shadow-sm' : 'border-ink/10 bg-paper/60 hover:border-ink/30' }}">
                            <x-staff-avatar :staff="$member" size="size-12" class="text-lg" />
                            <span class="flex-1">
                                <span class="block font-medium">{{ $member->name }}</span>
                                <span class="block text-sm text-ink-soft">{{ $member->title }}</span>
                            </span>
                            <span class="text-sm tabular-nums">{{ Money::format($price) }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            {{-- Bước 4: thông tin & xác nhận --}}
            @if ($step === 4)
                <h2 class="font-display text-2xl">Thông tin của bạn</h2>
                <p class="mt-1 text-sm text-ink-soft">Tiệm dùng số điện thoại để liên hệ khi cần. Có email thì bạn nhận xác nhận và nhắc lịch.</p>
                {{-- Tóm tắt cho điện thoại (cột tóm tắt chỉ hiện trên desktop) --}}
                <div class="mt-6 rounded-2xl border border-ink/10 bg-paper p-4 text-sm lg:hidden">
                    <p class="font-medium">{{ $this->selectedServices->pluck('name')->implode(', ') }}</p>
                    <p class="mt-1 text-ink-soft">{{ $time }}, {{ $longWeekdays[$chosenDate->dayOfWeek] }} {{ $chosenDate->format('d/m') }} · {{ $chosenStaff?->name ?? 'Bất kỳ ai' }} · {{ $duration }} phút</p>
                    <p class="mt-2 font-display text-xl tabular-nums">{{ Money::format($total) }}@if ($discount) <span class="text-sm text-sage">(đã giảm {{ Money::format($discount) }})</span>@endif</p>
                </div>

                <form wire:submit="submit" class="mt-6 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-sm font-medium">Họ tên</span>
                        <input type="text" wire:model="name" autocomplete="name" class="field mt-1.5" placeholder="Nguyễn Thị Mai">
                        @error('name') <span class="mt-1 block text-sm text-clay-dark">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Số điện thoại</span>
                        <input type="tel" wire:model.blur="phone" autocomplete="tel" inputmode="tel" class="field mt-1.5" placeholder="09xx xxx xxx">
                        @error('phone') <span class="mt-1 block text-sm text-clay-dark">{{ $message }}</span> @enderror
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium">Email <span class="font-normal text-ink-faint">(không bắt buộc)</span></span>
                        <input type="email" wire:model="email" autocomplete="email" class="field mt-1.5" placeholder="ban@email.com">
                        @error('email') <span class="mt-1 block text-sm text-clay-dark">{{ $message }}</span> @enderror
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="text-sm font-medium">Ghi chú cho tiệm <span class="font-normal text-ink-faint">(không bắt buộc)</span></span>
                        <textarea wire:model="note" rows="3" class="field mt-1.5" placeholder="Tóc dài ngang vai, da đầu nhạy cảm..."></textarea>
                        @error('note') <span class="mt-1 block text-sm text-clay-dark">{{ $message }}</span> @enderror
                    </label>

                    <div class="sm:col-span-2">
                        <span class="text-sm font-medium">Mã giảm giá</span>
                        @if ($voucher)
                            <div class="mt-1.5 flex items-center justify-between rounded-xl bg-sage-soft px-4 py-3 text-sm">
                                <span><b>{{ $voucher['code'] }}</b> · giảm {{ Money::format($voucher['discount']) }}</span>
                                <button type="button" wire:click="removeVoucher" class="text-sage underline underline-offset-4">Bỏ</button>
                            </div>
                        @else
                            <div class="mt-1.5 flex gap-2">
                                <input type="text" wire:model="voucherCode" class="field uppercase" placeholder="VD: WELCOME10">
                                <button type="button" wire:click="applyVoucher" class="btn-ghost shrink-0">Áp dụng</button>
                            </div>
                            @error('voucherCode') <span class="mt-1 block text-sm text-clay-dark">{{ $message }}</span> @enderror
                        @endif
                    </div>

                    {{-- Ô bẫy bot: người dùng không thấy --}}
                    <div class="absolute -left-[9999px]" aria-hidden="true">
                        <input type="text" wire:model="website" tabindex="-1" autocomplete="off">
                    </div>

                    @error('form')
                        <p class="rounded-xl bg-clay-soft px-4 py-3 text-sm text-clay-dark sm:col-span-2">{{ $message }}</p>
                    @enderror

                    <button type="submit" class="btn-accent py-4 text-base sm:col-span-2" wire:loading.attr="disabled" wire:target="submit">
                        <span wire:loading.remove wire:target="submit">Gửi yêu cầu đặt lịch</span>
                        <span wire:loading wire:target="submit">Đang giữ chỗ…</span>
                    </button>
                    <p class="text-center text-xs text-ink-faint sm:col-span-2">Lịch ở trạng thái <b>chờ xác nhận</b> cho tới khi tiệm duyệt. Bạn có thể hủy bất cứ lúc nào trước khi được xác nhận.</p>
                </form>
            @endif
        </div>
    </div>

    {{-- Tóm tắt: cột phải trên desktop, thanh dính đáy trên điện thoại --}}
    <aside class="lg:sticky lg:top-24 lg:self-start">
        <div class="hidden rounded-3xl border border-ink/10 bg-paper p-6 lg:block">
            <p class="font-display text-lg">Lịch hẹn của bạn</p>
            @if ($this->selectedServices->isEmpty())
                <p class="mt-4 text-sm text-ink-faint">Chưa chọn dịch vụ nào.</p>
            @else
                <ul class="mt-4 space-y-2 text-sm">
                    @foreach ($this->lineItems as $item)
                        <li class="flex items-baseline"><span>{{ $item['name'] }}</span><span class="leader"></span><span class="tabular-nums">{{ Money::format($item['price']) }}</span></li>
                    @endforeach
                </ul>
                <dl class="mt-5 space-y-2 border-t border-ink/10 pt-4 text-sm">
                    <div class="flex justify-between"><dt class="text-ink-soft">Thời lượng</dt><dd>{{ $duration }} phút</dd></div>
                    @if ($chosenDate)
                        <div class="flex justify-between"><dt class="text-ink-soft">Ngày</dt><dd>{{ $longWeekdays[$chosenDate->dayOfWeek] }}, {{ $chosenDate->format('d/m') }}</dd></div>
                    @endif
                    @if ($time)
                        <div class="flex justify-between"><dt class="text-ink-soft">Giờ</dt><dd class="tabular-nums">{{ $time }}</dd></div>
                    @endif
                    @if ($step >= 3)
                        <div class="flex justify-between"><dt class="text-ink-soft">Người thợ</dt><dd>{{ $chosenStaff?->name ?? 'Bất kỳ ai' }}</dd></div>
                    @endif
                    @if ($discount)
                        <div class="flex justify-between text-sage"><dt>Giảm giá</dt><dd class="tabular-nums">-{{ Money::format($discount) }}</dd></div>
                    @endif
                </dl>
                <div class="mt-4 flex items-baseline justify-between border-t border-ink/10 pt-4">
                    <span class="text-sm text-ink-soft">Tạm tính</span>
                    <span class="font-display text-2xl tabular-nums">{{ Money::format($total) }}</span>
                </div>
                @if ($this->priceRange)
                    <p class="mt-1 text-right text-xs text-ink-faint">Tùy thợ: {{ Money::format($this->priceRange[0]) }} – {{ Money::format($this->priceRange[1]) }}</p>
                @endif
            @endif
            @if ($step < 4)
                <button type="button" wire:click="next" @disabled(! $canNext) class="btn-primary mt-6 w-full py-3.5">Tiếp tục</button>
            @endif
        </div>

        @if ($step < 4)
            <div class="fixed inset-x-0 bottom-0 z-20 border-t border-ink/10 bg-paper/95 px-5 py-3 backdrop-blur lg:hidden">
                <div class="mx-auto flex max-w-6xl items-center gap-4">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-xs text-ink-soft">
                            {{ $this->selectedServices->count() ? $this->selectedServices->count().' dịch vụ · '.$duration.' phút' : 'Chưa chọn dịch vụ' }}{{ $time ? ' · '.$time.' '.$chosenDate->format('d/m') : '' }}
                        </p>
                        <p class="font-display text-xl tabular-nums">{{ Money::format($total) }}</p>
                    </div>
                    <button type="button" wire:click="next" @disabled(! $canNext) class="btn-primary px-7 py-3">Tiếp tục</button>
                </div>
            </div>
        @endif
    </aside>
</div>
