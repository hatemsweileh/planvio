@props([
    'size' => 'md',
    'icon' => null,
    'invalid' => false,
    'busyTarget' => null,
])

@php
    /*
       `busyTarget` names the Livewire property or method this input drives — pass
       `busy-target="search"` beside `wire:model.live="search"`. It renders a spinner at
       the trailing edge while that specific round trip is in flight.

       It is opt-in rather than automatic because `wire:loading` with no target matches
       *every* request the component makes, so a search box would spin while an unrelated
       button elsewhere on the page was saving.

       `.delay` is what stops it being noise: Livewire holds the indicator back ~200ms, so
       a fast local response never flickers a spinner, and a slow one is explained.
    */
    $hasTrailing = $busyTarget !== null;

    $sizes = [
        'sm' => 'h-7 text-xs '.($icon ? 'ps-7 ' : 'ps-2.5 ').($hasTrailing ? 'pe-7' : 'pe-2.5'),
        'md' => 'h-8 text-sm '.($icon ? 'ps-8 ' : 'ps-3 ').($hasTrailing ? 'pe-8' : 'pe-3'),
        'lg' => 'h-10 text-sm '.($icon ? 'ps-10 ' : 'ps-3.5 ').($hasTrailing ? 'pe-10' : 'pe-3.5'),
    ];
    $iconPos = ['sm' => 'start-2 size-3.5', 'md' => 'start-2.5 size-4', 'lg' => 'start-3 size-4.5'];
    $busyPos = ['sm' => 'end-2 size-3.5', 'md' => 'end-2.5 size-4', 'lg' => 'end-3 size-4.5'];
@endphp

<div class="relative">
    @if ($icon)
        <x-dynamic-component :component="$icon"
            class="pointer-events-none absolute top-1/2 -translate-y-1/2 text-[var(--text-subtle)] {{ $iconPos[$size] ?? $iconPos['md'] }}" />
    @endif

    <input {{ $attributes->merge([
        'class' => 'block w-full rounded-md border bg-[var(--surface-panel)] text-[var(--text-strong)] '
            .'placeholder:text-[var(--text-subtle)] shadow-xs transition-[border-color,box-shadow] '
            .'focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)] '
            .'disabled:cursor-not-allowed disabled:opacity-60 '
            .($invalid
                ? 'border-critical-500 focus:border-critical-500 focus:ring-critical-500/25'
                : 'border-[var(--line-DEFAULT)] focus:border-[var(--accent)]')
            .' '.($sizes[$size] ?? $sizes['md']),
        'aria-invalid' => $invalid ? 'true' : null,
    ]) }}>

    @if ($hasTrailing)
        <span wire:loading.delay wire:target="{{ $busyTarget }}"
              class="pointer-events-none absolute top-1/2 -translate-y-1/2 {{ $busyPos[$size] ?? $busyPos['md'] }}"
              role="status" aria-live="polite">
            <span class="sr-only">{{ __('Searching…') }}</span>
            <svg class="size-full animate-spin text-[var(--text-subtle)]" viewBox="0 0 16 16" fill="none" aria-hidden="true">
                <circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-opacity="0.25" stroke-width="2" />
                <path d="M14.25 8A6.25 6.25 0 0 0 8 1.75" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
        </span>
    @endif
</div>
