@props(['icon' => null, 'href' => null, 'danger' => false, 'active' => false, 'shortcut' => null])

@php
    $tag = $href ? 'a' : 'button';
    $tone = $danger
        ? 'text-critical-600 hover:bg-critical-50 dark:hover:bg-critical-950'
        : 'text-[var(--text-DEFAULT)] hover:bg-[var(--surface-hover)]';
@endphp

<{{ $tag }} {{ $attributes->merge([
    'class' => 'flex w-full items-center gap-2 rounded px-2 py-1.5 text-sm transition-colors '
        .$tone.' '.($active ? 'bg-[var(--surface-hover)]' : ''),
    'href' => $href,
    'type' => $href ? null : 'button',
    'role' => 'menuitem',
]) }} x-on:click="open = false">
    @if ($icon)
        <x-dynamic-component :component="$icon" class="size-4 shrink-0 text-[var(--text-subtle)]" />
    @endif
    <span class="flex-1 truncate text-start">{{ $slot }}</span>
    @if ($shortcut)
        <kbd class="rounded border border-[var(--line-subtle)] bg-[var(--surface-sunken)] px-1
                    text-[10px] font-medium text-[var(--text-subtle)]">{{ $shortcut }}</kbd>
    @endif
</{{ $tag }}>
