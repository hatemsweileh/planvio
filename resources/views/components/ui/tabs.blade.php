@props(['variant' => 'underline'])

<div {{ $attributes->merge([
    'class' => $variant === 'pill'
        ? 'inline-flex items-center gap-0.5 rounded-lg bg-[var(--surface-sunken)] p-0.5'
        : 'flex items-center gap-1 overflow-x-auto border-b border-[var(--line-subtle)] no-scrollbar',
]) }} role="tablist">
    {{ $slot }}
</div>
