@props([
    'href' => '#',
    'icon' => null,
    'label' => '',
    'active' => false,
    'badge' => null,
    'color' => null,
])

{{--
    Reads `collapsed` from the sidebar's Alpine scope. When collapsed the label and badge
    are hidden but stay in the DOM, so the accessible name and the tooltip both survive.
--}}
<a href="{{ $href }}"
   @if ($active) aria-current="page" @endif
   {{ $attributes->merge([
       'class' => 'group relative flex h-8 items-center gap-2.5 rounded-md px-2 text-sm '
           .'transition-colors duration-100 '
           .($active
               ? 'bg-[var(--accent-soft)] font-medium text-[var(--accent-soft-text)]'
               : 'text-[var(--text-muted)] hover:bg-[var(--surface-hover)] hover:text-[var(--text-DEFAULT)]'),
   ]) }}>

    @if ($color)
        <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $color }}" aria-hidden="true"></span>
    @elseif ($icon)
        <x-dynamic-component :component="$icon"
            class="size-4 shrink-0 {{ $active ? '' : 'text-[var(--text-subtle)] group-hover:text-[var(--text-muted)]' }}" />
    @endif

    <span dir="auto" x-show="!collapsed" x-cloak class="min-w-0 flex-1 truncate">{{ $label }}</span>

    @if ($badge)
        <span x-show="!collapsed" x-cloak
              class="shrink-0 rounded bg-[var(--accent)] px-1.5 text-[10px] font-semibold tabular-nums text-white">
            {{ $badge > 99 ? '99+' : $badge }}
        </span>
    @endif

    {{--
        Collapsed rail: the label becomes a hover tooltip so the icons stay usable.

        This is the case that made the whole overlay change necessary. The tooltip used to
        be `absolute`, and the navigation it lives in is a scroller — so the label was
        clipped at the rail's edge and, for an item far enough down the list, cut off
        vertically as well. It is now anchored into the top layer like every other overlay,
        which is also why it can be driven by hovering the link rather than by a
        `group-hover` class that CSS could only express as a sibling of the clipped box.
    --}}
    <span x-show="collapsed" x-cloak class="contents">
        <span x-data="anchored({ placement: 'end', align: 'center' })"
              x-on:mouseenter="show()" x-on:mouseleave="hide()"
              x-on:focusin="show()" x-on:focusout="hide()"
              x-on:keydown.escape.window="hide()"
              class="contents">
            <span x-ref="trigger" class="absolute inset-0" aria-hidden="true"></span>

            <span x-ref="panel" popover="manual" role="tooltip" class="pv-overlay pointer-events-none">
                <span class="block whitespace-nowrap rounded bg-[var(--surface-inverse)] px-1.5 py-1
                             text-[11px] font-medium text-[var(--text-inverse)] shadow-raised">{{ $label }}</span>
            </span>
        </span>
    </span>
</a>
