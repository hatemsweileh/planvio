@props(['rows' => 3, 'lines' => 1])

{{--
    A placeholder for content that is on its way.

    Preferred over a lone spinner wherever the shape of the answer is already known: a
    list that dims and reflows into rows of about the right size does not move the page
    around when the real rows land, and it tells the reader *what* is coming rather than
    only that something is.

    `aria-hidden` with a live-region label beside it: the shimmer is decoration, and a
    screen reader should hear "loading" once, not a description of eight grey boxes.
--}}

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }} role="status" aria-live="polite">
    <span class="sr-only">{{ __('Loading…') }}</span>

    @for ($row = 0; $row < (int) $rows; $row++)
        <div class="flex items-center gap-2 rounded-md px-2.5 py-1.5" aria-hidden="true">
            <span class="pv-skeleton size-2 shrink-0 rounded-full"></span>
            <span class="pv-skeleton h-3 w-12 shrink-0 rounded"></span>
            <span class="pv-skeleton h-3 rounded" style="width: {{ [72, 58, 84, 64, 76][$row % 5] }}%"></span>
        </div>

        @for ($line = 1; $line < (int) $lines; $line++)
            <div class="px-2.5" aria-hidden="true">
                <span class="pv-skeleton block h-3 rounded" style="width: {{ [46, 62, 38][$line % 3] }}%"></span>
            </div>
        @endfor
    @endfor
</div>
