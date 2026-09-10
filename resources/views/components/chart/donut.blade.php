@props([
    'data' => [],
    'caption' => null,
    'labelHeading' => null,
    'valueHeading' => null,
    'size' => 168,
    'thickness' => 22,
    'centreValue' => null,
    'centreLabel' => null,
    'legend' => true,
    'emptyLabel' => null,
])

@php
    use App\Support\ChartPalette;
    use App\Support\Formats;

    $segments = [];
    $total = 0.0;

    foreach ($data as $index => $datum) {
        $value = max(0.0, (float) ($datum['value'] ?? 0));

        if ($value <= 0.0) {
            continue;
        }

        $total += $value;

        $segments[] = [
            'label' => (string) ($datum['label'] ?? ''),
            'value' => $value,
            'display' => (string) ($datum['display'] ?? Formats::number($value)),
            'color' => ChartPalette::color($datum['color'] ?? ChartPalette::seriesName($index)),
        ];
    }

    $radius = ((float) $size - (float) $thickness) / 2;
    $circumference = 2 * M_PI * $radius;
    $centre = (float) $size / 2;

    $offset = 0.0;

    foreach ($segments as $key => $segment) {
        $fraction = $total > 0 ? $segment['value'] / $total : 0.0;

        $segments[$key]['share'] = $fraction;
        $segments[$key]['percent'] = (int) round($fraction * 100);
        // A hairline gap between arcs so two adjacent segments of similar colour still read
        // as two. Never wider than the arc itself, or a 1% slice would vanish.
        $length = max(0.0, $fraction * $circumference - min(2.0, $fraction * $circumference / 2));
        $segments[$key]['dash'] = round($length, 3).' '.round($circumference - $length, 3);
        $segments[$key]['offset'] = round(-$offset * $circumference, 3);

        $offset += $fraction;
    }

    /*
     * A ring has no axis to mirror, but it does have a reading order: the arcs start at
     * twelve o'clock and run clockwise, which is "onward" only for a reader who starts on
     * the left. In an RTL page they run counter-clockwise instead, so the first segment is
     * still the first one the eye meets and the ring matches its own legend, which the flex
     * row has already moved to the other side.
     *
     * Reflecting the y axis rather than the x is what puts the start back at twelve: the
     * arcs are drawn from three o'clock and the stylesheet rotates the whole ring a quarter
     * turn, so a horizontal flip would land the start at six. Composed with that rotation,
     * a vertical flip is the horizontal mirror of the finished ring. There is no text
     * inside this SVG — the centre figure is HTML sitting over it — so nothing is reversed
     * by the reflection.
     */
    $rtl = ($textDirection ?? 'ltr') === 'rtl';

    $tip = array_map(static fn (array $s): array => [
        'label' => $s['label'],
        'display' => $s['display'],
        'percent' => $s['percent'].'%',
    ], $segments);
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }}
     x-data="{
        active: null,
        tipX: 0,
        tipY: 0,
        items: @js($tip),
        show(event, index) {
            const mark = event.currentTarget.getBoundingClientRect();
            const host = this.$refs.plot.getBoundingClientRect();
            this.tipX = mark.left - host.left + mark.width / 2;
            this.tipY = mark.top - host.top + mark.height / 2;
            this.active = index;
        },
        hide() { this.active = null; },
     }"
     x-ref="plot">

    @if ($segments === [])
        <p class="py-6 text-center text-xs text-[var(--text-subtle)]">
            {{ $emptyLabel ?? __('Nothing to plot in this range.') }}
        </p>
    @else
        <div class="flex flex-wrap items-center justify-center gap-x-6 gap-y-3 sm:flex-nowrap">
            <div class="relative shrink-0" style="width: {{ (int) $size }}px; max-width: 100%">
                <svg viewBox="0 0 {{ (int) $size }} {{ (int) $size }}"
                     dir="ltr"
                     class="block h-auto w-full -rotate-90"
                     aria-hidden="true"
                     focusable="false">
                    <g @if ($rtl) transform="translate(0 {{ (int) $size }}) scale(1 -1)" @endif>
                        <circle cx="{{ $centre }}" cy="{{ $centre }}" r="{{ round($radius, 3) }}"
                                fill="none" stroke="var(--surface-active)" stroke-width="{{ (int) $thickness }}" />

                        @foreach ($segments as $index => $segment)
                            <circle cx="{{ $centre }}" cy="{{ $centre }}" r="{{ round($radius, 3) }}"
                                    fill="none"
                                    stroke="{{ $segment['color'] }}"
                                    stroke-width="{{ (int) $thickness }}"
                                    stroke-dasharray="{{ $segment['dash'] }}"
                                    stroke-dashoffset="{{ $segment['offset'] }}"
                                    class="transition-[stroke-width] duration-100"
                                    x-bind:stroke-width="active === {{ $index }} ? {{ (int) $thickness + 4 }} : {{ (int) $thickness }}"
                                    x-on:mouseenter="show($event, {{ $index }})"
                                    x-on:mouseleave="hide()" />
                        @endforeach
                    </g>
                </svg>

                @if ($centreValue !== null || $centreLabel !== null)
                    <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                        @if ($centreValue !== null)
                            <span class="text-xl font-semibold tabular-nums leading-none text-[var(--text-strong)]">
                                {{ $centreValue }}
                            </span>
                        @endif
                        @if ($centreLabel !== null)
                            <span class="mt-1 text-2xs uppercase tracking-wide text-[var(--text-muted)]">
                                {{ $centreLabel }}
                            </span>
                        @endif
                    </div>
                @endif
            </div>

            @if ($legend)
                <ul class="min-w-0 flex-1 space-y-1.5">
                    @foreach ($segments as $segment)
                        <li class="flex items-center gap-2 text-xs">
                            <span class="size-2 shrink-0 rounded-full" style="background-color: {{ $segment['color'] }}"></span>
                            <span dir="auto" class="min-w-0 flex-1 truncate text-[var(--text-DEFAULT)]">{{ $segment['label'] }}</span>
                            <span class="shrink-0 font-medium tabular-nums text-[var(--text-strong)]">{{ $segment['display'] }}</span>
                            <span class="w-9 shrink-0 text-end tabular-nums text-[var(--text-subtle)]">{{ $segment['percent'] }}%</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <x-chart.tooltip>
            <span class="block text-2xs font-medium text-[var(--text-muted)]" x-text="items[active]?.label"></span>
            <span class="block text-xs font-semibold tabular-nums text-[var(--text-strong)]">
                <span x-text="items[active]?.display"></span>
                <span class="font-normal text-[var(--text-subtle)]" x-text="items[active]?.percent"></span>
            </span>
        </x-chart.tooltip>
    @endif

    <x-chart.data-table
        :caption="$caption"
        :columns="[$labelHeading ?? __('Category'), $valueHeading ?? __('Value'), __('Share')]"
        :rows="collect($segments)->map(fn ($s) => [$s['label'], $s['display'], $s['percent'].'%'])->all()" />
</div>
