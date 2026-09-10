@props(['rows' => 4, 'invalid' => false, 'autogrow' => false])

<textarea rows="{{ $rows }}"
    @if ($autogrow)
        x-data="{ grow() { $el.style.height = 'auto'; $el.style.height = $el.scrollHeight + 'px' } }"
        x-init="grow()" x-on:input="grow()"
    @endif
    {{ $attributes->merge([
        'class' => 'block w-full rounded-md border bg-[var(--surface-panel)] px-3 py-2 text-sm '
            .'text-[var(--text-strong)] placeholder:text-[var(--text-subtle)] shadow-xs '
            .'focus:outline-none focus:ring-2 focus:ring-[var(--accent-ring)] '
            .'disabled:cursor-not-allowed disabled:opacity-60 resize-y '
            .($invalid
                ? 'border-critical-500 focus:border-critical-500 focus:ring-critical-500/25'
                : 'border-[var(--line-DEFAULT)] focus:border-[var(--accent)]'),
        'aria-invalid' => $invalid ? 'true' : null,
    ]) }}>{{ $slot }}</textarea>
