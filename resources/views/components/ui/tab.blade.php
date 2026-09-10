@props(['active' => false, 'href' => null, 'variant' => 'underline', 'count' => null, 'icon' => null])

@php
    $tag = $href ? 'a' : 'button';
    $classes = $variant === 'pill'
        ? 'h-7 rounded-md px-2.5 text-xs font-medium transition-colors '
            .($active
                ? 'bg-[var(--surface-panel)] text-[var(--text-strong)] shadow-xs'
                : 'text-[var(--text-muted)] hover:text-[var(--text-DEFAULT)]')
        // -mb-px pulls the active underline onto the container's border so the two read
        // as a single line rather than two stacked rules.
        : 'relative -mb-px whitespace-nowrap border-b-2 px-3 pb-2.5 pt-2 text-sm font-medium transition-colors '
            .($active
                ? 'border-[var(--accent)] text-[var(--text-strong)]'
                : 'border-transparent text-[var(--text-muted)] hover:border-[var(--line-strong)] hover:text-[var(--text-DEFAULT)]');
@endphp

<{{ $tag }} {{ $attributes->merge([
    'class' => 'inline-flex items-center gap-1.5 '.$classes,
    'href' => $href,
    'type' => $href ? null : 'button',
    'role' => 'tab',
    'aria-selected' => $active ? 'true' : 'false',
]) }}>
    @if ($icon)<x-dynamic-component :component="$icon" class="size-4" />@endif
    {{ $slot }}
    @if (! is_null($count))
        <span class="rounded bg-[var(--surface-active)] px-1.5 text-[10px] font-medium tabular-nums
                     text-[var(--text-muted)]">{{ $count }}</span>
    @endif
</{{ $tag }}>
