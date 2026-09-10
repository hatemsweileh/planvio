@props([
    'value' => 0,
    'max' => 100,
    'size' => 'md',
    'color' => null,
    'showLabel' => false,
    'label' => null,
])

@php
    $percent = $max > 0 ? max(0, min(100, (int) round(($value / $max) * 100))) : 0;
    $heights = ['xs' => 'h-1', 'sm' => 'h-1.5', 'md' => 'h-2', 'lg' => 'h-2.5'];
    // Progress carries meaning: green once complete, brand while in flight.
    $fill = $color ?? ($percent >= 100 ? 'bg-positive-500' : 'bg-[var(--accent)]');
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    <div class="flex-1 overflow-hidden rounded-full bg-[var(--surface-active)] {{ $heights[$size] ?? $heights['md'] }}"
         role="progressbar"
         aria-valuenow="{{ $percent }}"
         aria-valuemin="0"
         aria-valuemax="100"
         aria-label="{{ $label ?? __('Progress') }}">
        <div class="h-full rounded-full transition-[width] duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] {{ $fill }}"
             style="width: {{ $percent }}%"></div>
    </div>
    @if ($showLabel)
        <span class="w-9 shrink-0 text-end text-xs tabular-nums text-[var(--text-muted)]">{{ $percent }}%</span>
    @endif
</div>
