@props(['class' => 'size-4'])
<svg {{ $attributes->merge(['class' => $class.' animate-spin']) }} viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="2.5" class="opacity-20" />
    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" />
</svg>
