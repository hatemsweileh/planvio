@props([
    'color' => 'gray',
    'size' => 'md',
    'dot' => false,
    'icon' => null,
])

@php
    // Colours are semantic tokens produced by the enums (Priority::color(),
    // StatusCategory::color(), ...) so a status never hard-codes a hex anywhere.
    $palette = [
        'gray'   => 'bg-ink-100 text-ink-700 dark:bg-ink-800 dark:text-ink-300',
        'brand'  => 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200',
        'blue'   => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-200',
        'green'  => 'bg-positive-50 text-positive-700 dark:bg-positive-500/15 dark:text-positive-100',
        'amber'  => 'bg-caution-50 text-caution-700 dark:bg-caution-500/15 dark:text-caution-100',
        'orange' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-200',
        'red'    => 'bg-critical-50 text-critical-700 dark:bg-critical-500/15 dark:text-critical-100',
        'purple' => 'bg-accent-50 text-accent-600 dark:bg-accent-500/15 dark:text-accent-100',
        'teal'   => 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-200',
        'pink'   => 'bg-pink-50 text-pink-700 dark:bg-pink-500/15 dark:text-pink-200',
    ];
    $dots = [
        'gray' => 'bg-ink-400', 'brand' => 'bg-brand-500', 'blue' => 'bg-blue-500',
        'green' => 'bg-positive-500', 'amber' => 'bg-caution-500', 'orange' => 'bg-orange-500',
        'red' => 'bg-critical-500', 'purple' => 'bg-accent-500', 'teal' => 'bg-teal-500',
        'pink' => 'bg-pink-500',
    ];
    $sizes = [
        'sm' => 'h-4.5 px-1.5 text-2xs gap-1',
        'md' => 'h-5.5 px-2 text-xs gap-1.5',
        'lg' => 'h-7 px-2.5 text-sm gap-1.5',
    ];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex items-center rounded font-medium whitespace-nowrap '
        .($palette[$color] ?? $palette['gray']).' '.($sizes[$size] ?? $sizes['md']),
]) }}>
    @if ($dot)
        <span class="size-1.5 rounded-full {{ $dots[$color] ?? $dots['gray'] }}" aria-hidden="true"></span>
    @elseif ($icon)
        <x-dynamic-component :component="$icon" class="size-3.5 shrink-0" />
    @endif
    {{ $slot }}
</span>
