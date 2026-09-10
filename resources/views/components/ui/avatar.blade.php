@props([
    'user' => null,
    'name' => null,
    'src' => null,
    'size' => 'md',
    'ring' => false,
])

@php
    $displayName = $name ?? $user?->name ?? '?';
    $image = $src ?? $user?->avatar_path;

    $sizes = [
        'xs' => 'size-5 text-[9px]',
        'sm' => 'size-6 text-[10px]',
        'md' => 'size-7 text-xs',
        'lg' => 'size-9 text-sm',
        'xl' => 'size-12 text-base',
        '2xl' => 'size-16 text-xl',
    ];

    // Deterministic colour from the name: the same person is the same colour on every
    // screen and after every deploy, which is what makes an avatar scannable.
    $tones = [
        'bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200',
        'bg-accent-100 text-accent-600 dark:bg-accent-500/20 dark:text-accent-100',
        'bg-positive-100 text-positive-700 dark:bg-positive-500/20 dark:text-positive-100',
        'bg-caution-100 text-caution-700 dark:bg-caution-500/20 dark:text-caution-100',
        'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-200',
        'bg-pink-100 text-pink-700 dark:bg-pink-500/20 dark:text-pink-200',
    ];
    $tone = $tones[abs(crc32($displayName)) % count($tones)];

    $initials = collect(preg_split('/\s+/', trim($displayName)))
        ->filter()
        ->take(2)
        ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: '?';
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold '
        .'uppercase leading-none select-none '.($sizes[$size] ?? $sizes['md']).' '
        .($image ? 'bg-[var(--surface-active)]' : $tone).' '
        .($ring ? 'ring-2 ring-[var(--surface-panel)]' : ''),
    'title' => $displayName,
]) }}>
    @if ($image)
        <img src="{{ \Illuminate\Support\Str::startsWith($image, ['http', '/']) ? $image : \Illuminate\Support\Facades\Storage::disk('public')->url($image) }}"
             alt="{{ $displayName }}" class="size-full object-cover" loading="lazy">
    @else
        {{ $initials }}
    @endif
</span>
