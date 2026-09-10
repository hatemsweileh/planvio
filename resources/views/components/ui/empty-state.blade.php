@props([
    'icon' => null,
    'title' => null,
    'description' => null,
    'compact' => false,
])

{{--
    Every empty state is a designed moment, not an absence. It says what this space is
    for and offers the one action that fills it.
--}}
<div {{ $attributes->merge([
    'class' => 'flex flex-col items-center justify-center text-center '.($compact ? 'px-4 py-8' : 'px-6 py-14'),
]) }}>
    @if ($icon)
        <div class="mb-3 grid place-items-center rounded-xl border border-[var(--line-subtle)]
                    bg-[var(--surface-sunken)] {{ $compact ? 'size-9' : 'size-11' }}">
            <x-dynamic-component :component="$icon" class="{{ $compact ? 'size-4.5' : 'size-5' }} text-[var(--text-subtle)]" />
        </div>
    @endif

    @if ($title)
        <h3 class="{{ $compact ? 'text-sm' : 'text-[0.9375rem]' }} font-semibold text-[var(--text-strong)]">{{ $title }}</h3>
    @endif

    @if ($description)
        <p class="mt-1 max-w-sm text-pretty text-xs leading-relaxed text-[var(--text-muted)]">{{ $description }}</p>
    @endif

    @isset($actions)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $actions }}</div>
    @endisset

    {{ $slot }}
</div>
