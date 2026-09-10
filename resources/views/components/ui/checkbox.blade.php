@props(['label' => null, 'description' => null])

<label class="group flex items-start gap-2 {{ $label ? 'cursor-pointer' : '' }}">
    <input type="checkbox" {{ $attributes->merge([
        'class' => 'mt-0.5 size-4 shrink-0 rounded border-[var(--line-strong)] bg-[var(--surface-panel)] '
            .'text-[var(--accent)] shadow-xs transition-colors '
            .'focus:ring-2 focus:ring-[var(--accent-ring)] focus:ring-offset-0 '
            .'checked:border-[var(--accent)] checked:bg-[var(--accent)] '
            .'disabled:cursor-not-allowed disabled:opacity-60',
    ]) }}>
    @if ($label || $description || $slot->isNotEmpty())
        <span class="min-w-0">
            @if ($label)
                <span class="block text-sm text-[var(--text-DEFAULT)]">{{ $label }}</span>
            @endif
            @if ($description)
                <span class="block text-xs text-[var(--text-muted)]">{{ $description }}</span>
            @endif
            {{ $slot }}
        </span>
    @endif
</label>
