@props(['align' => 'start', 'width' => 'w-56', 'placement' => 'bottom'])

{{--
    A menu that cannot be clipped and cannot leave the window.

    The panel is rendered into the top layer with the Popover API, so it is no longer at
    the mercy of whatever `overflow: hidden` happens to sit between it and the page —
    previously a menu opened from inside the sidebar, a board column or a horizontally
    scrolling table was simply cut off. `Alpine.data('anchored')` then places it against
    the viewport: it flips to the other side of the trigger when there is no room below,
    slides along to stay inside the edges, and caps its own height so a long menu scrolls
    itself instead of running past the bottom of the screen.

    `align` is logical — `start` and `end` follow the reading direction — and is resolved
    in JavaScript, which is why no direction-aware classes remain in this file.
--}}

<div x-data="anchored({ placement: '{{ $placement }}', align: '{{ $align }}' })"
     x-on:keydown.escape.window="hide()"
     x-on:close-dropdowns.window="hide()"
     x-on:click.outside="onOutside($event)"
     {{ $attributes->merge(['class' => 'relative']) }}>

    <div x-ref="trigger" x-on:click="toggle()" :aria-expanded="open" aria-haspopup="menu">
        {{ $trigger }}
    </div>

    <div x-ref="panel" popover="manual" role="menu" class="pv-overlay {{ $width }}">
        {{-- The inner surface carries the scroll, so the cap the positioner writes onto
             the panel turns a long menu into a scrolling one rather than a clipped one. --}}
        <div class="pv-overlay-surface scrollbar-thin rounded-lg border border-[var(--line-subtle)]
                    bg-[var(--surface-raised)] p-1 shadow-overlay">
            {{ $slot }}
        </div>
    </div>
</div>
