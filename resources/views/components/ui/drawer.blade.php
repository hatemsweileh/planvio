@props(['title' => null, 'size' => 'lg', 'side' => 'right'])

@php
    $widths = ['sm' => 'max-w-md', 'md' => 'max-w-xl', 'lg' => 'max-w-2xl', 'xl' => 'max-w-4xl'];
    /*
       `side` names the edge in reading order rather than on the screen: the drawer that
       opens from the right in English opens from the left in Arabic, which is the same
       side of the sentence. `slide-from-*` carries the direction because Alpine swaps
       transition classes whole and a translate cannot be expressed logically.
    */
    $edge = $side === 'left' ? 'start-0' : 'end-0';
    $from = $side === 'left' ? 'slide-from-start' : 'slide-from-end';
@endphp

{{--
    Task detail opens in here rather than on its own page. The board or list stays
    visible behind the scrim, so closing the drawer returns you to exactly where you
    were - scroll position, filters and all.
--}}
<div x-data="{ open: @entangle($attributes->wire('model')).live }"
     x-show="open" x-cloak
     x-on:keydown.escape.window="open = false"
     class="fixed inset-0 z-50" role="dialog" aria-modal="true"
     @if ($title) aria-label="{{ $title }}" @endif>

    <div x-show="open"
         x-transition:enter="ease-out duration-150" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-120" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="absolute inset-0 bg-ink-950/30" x-on:click="open = false" aria-hidden="true"></div>

    <div x-show="open"
         x-transition:enter="transform transition ease-[cubic-bezier(0.32,0.72,0,1)] duration-250"
         x-transition:enter-start="{{ $from }}" x-transition:enter-end="translate-x-0"
         x-transition:leave="transform transition ease-in duration-180"
         x-transition:leave-start="translate-x-0" x-transition:leave-end="{{ $from }}"
         x-trap.noscroll="open"
         class="absolute inset-y-0 {{ $edge }} flex w-full {{ $widths[$size] ?? $widths['lg'] }}
                flex-col border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-overlay
                {{ $side === 'left' ? 'border-e' : 'border-s' }}">
        {{ $slot }}
    </div>
</div>
