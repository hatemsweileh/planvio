@props(['size' => 'md', 'invalid' => false, 'placeholder' => null, 'options' => null])

@php
    $sizes = ['sm' => 'h-7 ps-2.5 pe-7 text-xs', 'md' => 'h-8 ps-3 pe-8 text-sm', 'lg' => 'h-10 ps-3.5 pe-9 text-sm'];
@endphp

<div class="relative">
    <select {{ $attributes->merge([
        'class' => 'block w-full appearance-none rounded-md border bg-[var(--surface-panel)] '
            .'text-[var(--text-strong)] shadow-xs focus:outline-none focus:ring-2 '
            .'focus:ring-[var(--accent-ring)] disabled:cursor-not-allowed disabled:opacity-60 '
            .($invalid ? 'border-critical-500' : 'border-[var(--line-DEFAULT)] focus:border-[var(--accent)]')
            .' '.($sizes[$size] ?? $sizes['md']),
        'aria-invalid' => $invalid ? 'true' : null,
    ]) }}>
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @if ($options)
            @foreach ($options as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        @endif
        {{ $slot }}
    </select>
    <svg class="pointer-events-none absolute end-2 top-1/2 size-4 -translate-y-1/2 text-[var(--text-subtle)]"
         viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
        <path d="m6 8 4 4 4-4" stroke-linecap="round" stroke-linejoin="round"/>
    </svg>
</div>
