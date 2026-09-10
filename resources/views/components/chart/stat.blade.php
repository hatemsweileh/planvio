@props([
    'label' => '',
    'value' => '',
    'hint' => null,
    'tone' => 'neutral',
    'icon' => null,
])

@php
    /*
     * A single figure, sized to be read across a room and captioned so it cannot be
     * misread. The tone is a key, never an interpolated class: Tailwind scans source text
     * and never evaluates it, so `text-{{ $tone }}-600` compiles to nothing at all.
     */
    $tones = [
        'neutral' => 'text-[var(--text-strong)]',
        'accent' => 'text-[var(--accent)]',
        'positive' => 'text-positive-600 dark:text-positive-500',
        'caution' => 'text-caution-600 dark:text-caution-500',
        'critical' => 'text-critical-600 dark:text-critical-500',
    ];
@endphp

<div {{ $attributes->merge([
    'class' => 'rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] px-3 py-2.5 shadow-panel',
]) }}>
    <div class="flex items-center gap-1.5">
        @if ($icon)
            <x-dynamic-component :component="$icon" class="size-3.5 text-[var(--text-subtle)]" />
        @endif
        <p class="truncate text-2xs font-medium uppercase tracking-wider text-[var(--text-muted)]">{{ $label }}</p>
    </div>
    <p class="mt-1 text-xl font-semibold tabular-nums leading-none {{ $tones[$tone] ?? $tones['neutral'] }}">
        {{ $value }}
    </p>
    @if ($hint)
        <p class="mt-1 truncate text-2xs text-[var(--text-subtle)]">{{ $hint }}</p>
    @endif
    {{ $slot }}
</div>
