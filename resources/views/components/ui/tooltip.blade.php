@props(['label' => null, 'position' => 'top'])

@php
    /*
       Placement is logical and is resolved in JavaScript against the document's
       direction, so `left` and `right` keep the meaning they have always had here —
       *before* and *after* the trigger along the reading line, which is the left and the
       right in English and the reverse in Arabic. Nothing in this file needs to know
       which is which.

       The panel itself is no longer positioned by CSS at all. It used to be
       `absolute`, which meant any scrolling ancestor — the sidebar, a board column, a
       table with a horizontal scroller — clipped it, and a tooltip on a control near the
       edge of the window simply ran off the screen. See `Alpine.data('anchored')` in
       resources/js/app.js.
    */
    $placement = match ($position) {
        'bottom' => 'bottom',
        'left' => 'start',
        'right' => 'end',
        default => 'top',
    };

    $id = 'tt-'.\Illuminate\Support\Str::random(8);
@endphp

<span x-data="anchored({ placement: '{{ $placement }}', align: 'center' })"
      class="inline-flex"
      @if ($label)
          x-on:mouseenter="show()"
          x-on:mouseleave="hide()"
          x-on:focusin="show()"
          x-on:focusout="hide()"
          {{-- A tooltip must never survive the thing it describes going away. --}}
          x-on:keydown.escape.window="hide()"
          aria-describedby="{{ $id }}"
      @endif>
    {{ $slot }}

    @if ($label)
        <div x-ref="panel" popover="manual" id="{{ $id }}" role="tooltip"
             class="pv-overlay pointer-events-none">
            {{-- Wraps rather than runs on: the positioner caps the panel's width near a
                 window edge, and a nowrap label would then simply overflow the cap. --}}
            <span class="block max-w-[min(18rem,90vw)] text-balance rounded bg-[var(--surface-inverse)]
                         px-1.5 py-1 text-[11px] font-medium leading-snug
                         text-[var(--text-inverse)] shadow-raised">{{ $label }}</span>
        </div>
    @endif
</span>
