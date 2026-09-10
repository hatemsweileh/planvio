@props([])

{{--
    The floating readout shared by every chart.

    Positioned from the hovered shape's own bounding box rather than from the pointer, so
    it sits on the mark instead of chasing the cursor, and `pointer-events-none` keeps it
    from stealing the hover that produced it.

    It expects three properties on the surrounding Alpine scope — `active` (an index or
    null), `tipX` and `tipY` — which `chart.frame` provides.
--}}
<div x-show="active !== null"
     x-cloak
     x-transition:enter="transition ease-out duration-75"
     x-transition:enter-start="opacity-0 translate-y-0.5"
     x-transition:enter-end="opacity-100 translate-y-0"
     {{--
         Kept inside the plot rather than centred blindly on the mark.

         The readout is centred with `-translate-x-1/2`, so a point at either end of the
         axis put half of it outside the chart — and for the last point of a full-width
         chart, outside the window, which is the one place it can never be read. The
         centre is therefore clamped to half the readout's own width from each edge. It
         stops tracking the mark exactly at the extremes, which is the right trade: a
         readout slightly off its point is still legible, and one off the screen is not.

         Clamped against the chart rather than the window because the chart is always
         within the window, and measuring the nearer box keeps this independent of where
         on the page the chart happens to sit.
     --}}
     {{-- Placed twice, on purpose, because each pass covers the other's blind spot.

          The binding is reactive and is correct every time the readout is already on
          screen, which is every case but one. The effect handles that one: on the first
          show `x-show` has not revealed the element yet, so `offsetWidth` is still zero
          and the binding cannot know how wide the box it is centring will be. Measuring
          again on the next tick fixes it before the enter transition has finished
          bringing the opacity up from zero.

          `tipX` and `tipY` are read into locals *synchronously*. Alpine records an
          effect's dependencies while it runs, so a value read inside the deferred
          callback would not be tracked and the effect would never re-run when the pointer
          moved to another mark. --}}
     :style="`left: ${Planvio.clampTip($el, tipX)}px; top: ${tipY}px`"
     x-effect="active !== null && ((x, y) => $nextTick(() => {
                   $el.style.left = Planvio.clampTip($el, x) + 'px';
                   $el.style.top = y + 'px';
               }))(tipX, tipY)"
     class="pointer-events-none absolute z-20 -translate-x-1/2 -translate-y-[calc(100%+8px)]
            max-w-[min(20rem,90vw)] rounded-md border border-[var(--line-subtle)]
            bg-[var(--surface-raised)] px-2 py-1.5 shadow-overlay print:hidden"
     role="presentation"
     aria-hidden="true">
    {{ $slot }}
</div>
