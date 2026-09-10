@props([
    'padding' => 'md',
    'title' => null,
    'subtitle' => null,
    'flush' => false,
])

@php
    $pad = ['none' => '', 'sm' => 'p-3', 'md' => 'p-4', 'lg' => 'p-5'][$padding] ?? 'p-4';
@endphp

<div {{ $attributes->merge([
    'class' => 'rounded-lg border border-[var(--line-subtle)] bg-[var(--surface-panel)] shadow-panel',
]) }}>
    @if ($title || isset($header))
        <div class="flex items-start justify-between gap-3 border-b border-[var(--line-subtle)] px-4 py-3">
            <div class="min-w-0">
                @if ($title)
                    <h3 class="truncate text-sm font-semibold text-[var(--text-strong)]">{{ $title }}</h3>
                @endif
                @if ($subtitle)
                    <p class="mt-0.5 truncate text-xs text-[var(--text-muted)]">{{ $subtitle }}</p>
                @endif
                {{ $header ?? '' }}
            </div>
            {{ $actions ?? '' }}
        </div>
    @endif

    <div class="{{ $flush ? '' : $pad }}">{{ $slot }}</div>

    @isset($footer)
        <div class="border-t border-[var(--line-subtle)] px-4 py-3">{{ $footer }}</div>
    @endisset
</div>
