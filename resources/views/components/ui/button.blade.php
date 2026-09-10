@props([
    'variant' => 'secondary',
    'size' => 'md',
    'icon' => null,
    'trailingIcon' => null,
    'href' => null,
    'loading' => false,
    'iconOnly' => false,
])

@php
    $base = 'relative inline-flex items-center justify-center gap-1.5 font-medium whitespace-nowrap '
        .'rounded-md border transition-[background-color,border-color,box-shadow,color] duration-100 '
        .'focus-visible:outline-2 focus-visible:outline-offset-2 '
        .'disabled:pointer-events-none disabled:opacity-50 select-none';

    $variants = [
        // Solid brand. One per view, on the single most likely next action.
        'primary' => 'bg-[var(--accent)] text-white border-transparent shadow-xs '
            .'hover:bg-[var(--accent-hover)] active:brightness-95 focus-visible:outline-[var(--accent)]',

        // The workhorse. Reads as a control, not as decoration.
        'secondary' => 'bg-[var(--surface-panel)] text-[var(--text-DEFAULT)] border-[var(--line-DEFAULT)] shadow-xs '
            .'hover:bg-[var(--surface-hover)] hover:border-[var(--line-strong)] '
            .'active:bg-[var(--surface-active)] focus-visible:outline-[var(--accent)]',

        // Toolbar and table-row actions: no chrome until you reach for it.
        'ghost' => 'bg-transparent text-[var(--text-muted)] border-transparent '
            .'hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)] '
            .'active:bg-[var(--surface-active)] focus-visible:outline-[var(--accent)]',

        'soft' => 'bg-[var(--accent-soft)] text-[var(--accent-soft-text)] border-transparent '
            .'hover:brightness-95 focus-visible:outline-[var(--accent)]',

        'danger' => 'bg-critical-600 text-white border-transparent shadow-xs '
            .'hover:bg-critical-700 focus-visible:outline-critical-600',

        'danger-ghost' => 'bg-transparent text-critical-600 border-transparent '
            .'hover:bg-critical-50 dark:hover:bg-critical-950 focus-visible:outline-critical-600',

        'link' => 'bg-transparent border-transparent text-[var(--accent)] underline underline-offset-2 '
            .'decoration-[color-mix(in_oklab,var(--accent)_40%,transparent)] hover:decoration-current px-0',
    ];

    // Heights are on a 4px rhythm and sized for a dense product UI, not a form-first admin.
    $sizes = [
        'xs' => $iconOnly ? 'size-6 text-2xs' : 'h-6 px-2 text-2xs',
        'sm' => $iconOnly ? 'size-7 text-xs' : 'h-7 px-2.5 text-xs',
        'md' => $iconOnly ? 'size-8 text-sm' : 'h-8 px-3 text-sm',
        'lg' => $iconOnly ? 'size-10 text-sm' : 'h-10 px-4 text-sm',
    ];

    $classes = $base.' '.($variants[$variant] ?? $variants['secondary']).' '.($sizes[$size] ?? $sizes['md']);
    $iconSize = match ($size) { 'xs' => 'size-3', 'sm' => 'size-3.5', 'lg' => 'size-4.5', default => 'size-4' };
    $tag = $href ? 'a' : 'button';
@endphp

<{{ $tag }}
    {{ $attributes->merge([
        'class' => $classes,
        'type' => $href ? null : ($attributes->get('type') ?? 'button'),
        'href' => $href,
    ])->when($loading, fn ($a) => $a->merge(['aria-busy' => 'true'])) }}
    @if ($loading) disabled @endif
>
    {{-- Livewire's own loading state, so a slow action never looks like a dead button. --}}
    @if ($attributes->has('wire:click') || $attributes->has('wire:target'))
        <span wire:loading.delay
              @if ($attributes->get('wire:click')) wire:target="{{ $attributes->get('wire:click') }}" @endif
              class="absolute inset-0 grid place-items-center">
            <x-ui.spinner :class="$iconSize" />
        </span>
        <span wire:loading.delay.remove
              @if ($attributes->get('wire:click')) wire:target="{{ $attributes->get('wire:click') }}" @endif
              class="contents">
            @if ($icon)<x-dynamic-component :component="$icon" class="{{ $iconSize }} shrink-0" />@endif
            @if (! $iconOnly || ! $icon) {{ $slot }} @endif
            @if ($trailingIcon)<x-dynamic-component :component="$trailingIcon" class="{{ $iconSize }} shrink-0" />@endif
        </span>
    @else
        @if ($loading)
            <x-ui.spinner :class="$iconSize.' shrink-0'" />
        @elseif ($icon)
            <x-dynamic-component :component="$icon" class="{{ $iconSize }} shrink-0" />
        @endif
        @if (! $iconOnly || ! $icon) {{ $slot }} @endif
        @if ($trailingIcon)<x-dynamic-component :component="$trailingIcon" class="{{ $iconSize }} shrink-0" />@endif
    @endif
</{{ $tag }}>
