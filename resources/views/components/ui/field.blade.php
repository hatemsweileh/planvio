@props([
    'label' => null,
    'for' => null,
    'hint' => null,
    'error' => null,
    'required' => false,
    'inline' => false,
])

<div {{ $attributes->merge(['class' => $inline ? 'flex items-center gap-3' : 'space-y-1.5']) }}>
    @if ($label)
        <label @if ($for) for="{{ $for }}" @endif
               class="block text-xs font-medium text-[var(--text-DEFAULT)] {{ $inline ? 'w-32 shrink-0' : '' }}">
            {{ $label }}
            @if ($required)
                <span class="text-critical-600" aria-hidden="true">*</span>
                <span class="sr-only">{{ __('required') }}</span>
            @endif
        </label>
    @endif

    <div class="{{ $inline ? 'flex-1 min-w-0' : '' }}">
        {{ $slot }}

        {{-- An error replaces the hint rather than stacking, so the row never jumps. --}}
        @if ($error)
            <p class="mt-1 flex items-start gap-1 text-xs text-critical-600" role="alert">
                <svg class="mt-0.5 size-3.5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0Zm-9-4a1 1 0 1 1 2 0v4a1 1 0 1 1-2 0V6Zm1 8a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/>
                </svg>
                <span>{{ $error }}</span>
            </p>
        @elseif ($hint)
            <p class="mt-1 text-xs text-[var(--text-muted)]">{{ $hint }}</p>
        @endif
    </div>
</div>
