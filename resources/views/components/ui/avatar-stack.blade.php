@props(['users' => [], 'max' => 4, 'size' => 'md'])

@php
    $users = collect($users);
    $shown = $users->take($max);
    $overflow = $users->count() - $shown->count();
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center -space-x-1.5']) }}>
    @foreach ($shown as $person)
        <x-ui.avatar :user="$person" :size="$size" ring />
    @endforeach
    @if ($overflow > 0)
        <span class="inline-flex items-center justify-center rounded-full bg-[var(--surface-active)]
                     text-[var(--text-muted)] font-medium ring-2 ring-[var(--surface-panel)]
                     {{ ['xs' => 'size-5 text-[9px]', 'sm' => 'size-6 text-[10px]', 'md' => 'size-7 text-xs', 'lg' => 'size-9 text-sm'][$size] ?? 'size-7 text-xs' }}">
            +{{ $overflow }}
        </span>
    @endif
</div>
