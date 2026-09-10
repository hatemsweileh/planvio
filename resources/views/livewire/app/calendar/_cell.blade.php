@php
    /**
     * One day of the month or week grid.
     *
     * The whole cell is the drop target — dropping on the gap between two chips has to mean
     * the same thing as dropping on the date number — and `over` gives the drag a visible
     * landing place, which is the difference between a drag that feels precise and one that
     * feels like a guess.
     *
     * Expects `$day`, `$compact` and `$scopedProject`.
     */
@endphp

<div x-data="{ over: false }"
     data-day="{{ $day['date'] }}"
     x-on:dragover.prevent="over = true"
     x-on:dragleave="over = false"
     x-on:drop.prevent="over = false; pick($event)"
     :class="over && 'ring-2 ring-inset ring-[var(--accent)] bg-[var(--accent-soft)]'"
     class="relative flex flex-col gap-0.5 border-e border-[var(--line-subtle)] p-1 last:border-e-0
            {{ $compact ? 'min-h-[6.25rem]' : 'min-h-[22rem]' }}
            {{ $day['inScope'] ? '' : 'bg-[var(--surface-sunken)]' }}
            {{ $day['isWeekend'] && $day['inScope'] ? 'bg-[var(--surface-sunken)]/60' : '' }}">

    <div class="flex items-center gap-1">
        <button type="button"
                wire:click="goToDay('{{ $day['date'] }}')"
                class="grid size-5 shrink-0 place-items-center rounded text-2xs font-medium tabular-nums
                       transition-colors
                       {{ $day['isToday']
                            ? 'bg-[var(--accent)] text-white'
                            : ($day['inScope'] ? 'text-[var(--text-muted)] hover:bg-[var(--surface-hover)]' : 'text-[var(--text-subtle)] hover:bg-[var(--surface-hover)]') }}"
                aria-label="{{ __('Open :date', ['date' => $day['label']]) }}">
            {{ $day['day'] }}
        </button>

        @if ($day['isFirstOfMonth'])
            <span class="text-2xs font-semibold uppercase tracking-wide text-[var(--text-subtle)]">
                {{ $day['monthLabel'] }}
            </span>
        @endif
    </div>

    <div class="{{ $compact ? '' : 'scrollbar-thin min-h-0 flex-1 overflow-y-auto' }} space-y-0.5">
        @foreach ($day['visible'] as $item)
            <div wire:key="cell-{{ $day['date'] }}-{{ $item['type'] }}-{{ $item['id'] }}-{{ $item['kind'] ?? 'x' }}">
                @include('livewire.app.calendar._event', ['item' => $item, 'dense' => $compact])
            </div>
        @endforeach
    </div>

    @if ($day['overflow'] > 0)
        <button type="button" wire:click="goToDay('{{ $day['date'] }}')"
                class="mt-auto rounded px-1.5 py-0.5 text-start text-2xs font-medium text-[var(--accent)]
                       transition-colors hover:bg-[var(--surface-hover)]">
            {{ trans_choice('{1}+:count more|[2,*]+:count more', $day['overflow'], ['count' => $day['overflow']]) }}
        </button>
    @endif
</div>
