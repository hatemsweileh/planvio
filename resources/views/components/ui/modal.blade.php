@props([
    'title' => null,
    'description' => null,
    'size' => 'md',
    'closable' => true,
])

@php
    $widths = [
        'sm' => 'max-w-sm', 'md' => 'max-w-lg', 'lg' => 'max-w-2xl',
        'xl' => 'max-w-4xl', 'full' => 'max-w-[min(64rem,95vw)]',
    ];
@endphp

<div x-data="{ open: @entangle($attributes->wire('model')).live }"
     x-show="open"
     x-cloak
     x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-50 overflow-y-auto"
     role="dialog"
     aria-modal="true"
     @if ($title) aria-label="{{ $title }}" @endif>

    <div x-show="open"
         x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 bg-ink-950/40 backdrop-blur-[2px]"
         @if ($closable) x-on:click="open = false" @endif
         aria-hidden="true"></div>

    {{-- Bottom sheet on phones, centred dialog from sm up: the same component, two idioms. --}}
    <div class="flex min-h-full items-end justify-center p-0 sm:items-center sm:p-4">
        <div x-show="open"
             x-transition:enter="ease-[cubic-bezier(0.32,0.72,0,1)] duration-200"
             x-transition:enter-start="opacity-0 translate-y-4 sm:scale-97 sm:translate-y-0"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-120"
             x-transition:leave-start="opacity-100 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-2 sm:scale-97"
             x-trap.noscroll="open"
             class="relative w-full {{ $widths[$size] ?? $widths['md'] }} rounded-t-xl border
                    border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-overlay sm:rounded-xl">

            @if ($title || $closable)
                <div class="flex items-start justify-between gap-4 px-5 pt-4 {{ $description ? 'pb-1' : 'pb-3' }}">
                    <div class="min-w-0">
                        @if ($title)
                            <h2 class="text-base font-semibold text-[var(--text-strong)]">{{ $title }}</h2>
                        @endif
                        @if ($description)
                            <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $description }}</p>
                        @endif
                    </div>
                    @if ($closable)
                        <button type="button" x-on:click="open = false"
                                class="-me-1.5 -mt-0.5 grid size-7 shrink-0 place-items-center rounded
                                       text-[var(--text-subtle)] transition-colors
                                       hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]"
                                aria-label="{{ __('Close') }}">
                            <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75">
                                <path d="m5 5 10 10M15 5 5 15" stroke-linecap="round"/>
                            </svg>
                        </button>
                    @endif
                </div>
            @endif

            <div class="px-5 {{ $title ? 'pb-4 pt-2' : 'py-4' }}">{{ $slot }}</div>

            @isset($footer)
                <div class="flex items-center justify-end gap-2 rounded-b-xl border-t
                            border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-5 py-3">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
