@props(['color' => 'gray', 'size' => 'md'])

@php
    // The same ten names the badge palette uses, so a status renders identically wherever
    // it appears. Written out in full: Tailwind scans source text and never evaluates it,
    // so a class built by interpolation would compile to nothing at all.
    $tones = [
        'gray'   => 'bg-ink-400',
        'brand'  => 'bg-brand-500',
        'blue'   => 'bg-blue-500',
        'green'  => 'bg-positive-500',
        'amber'  => 'bg-caution-500',
        'orange' => 'bg-orange-500',
        'red'    => 'bg-critical-500',
        'purple' => 'bg-accent-500',
        'teal'   => 'bg-teal-500',
        'pink'   => 'bg-pink-500',
    ];

    $sizes = ['sm' => 'size-1.5', 'md' => 'size-2', 'lg' => 'size-2.5'];
@endphp

<span {{ $attributes->merge([
    'class' => 'inline-block shrink-0 rounded-full '.($tones[$color] ?? $tones['gray']).' '.($sizes[$size] ?? $sizes['md']),
]) }} aria-hidden="true"></span>
