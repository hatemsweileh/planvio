@props([
    'data' => [],
    'caption' => null,
    'labelHeading' => null,
    'valueHeading' => null,
    'height' => 200,
    'orientation' => 'vertical',
    'max' => null,
    'emptyLabel' => null,
])

@php
    use App\Support\ChartPalette;
    use App\Support\Formats;

    /*
     * Normalised once, here, so the SVG below is pure geometry. Every datum carries its own
     * printed form: a chart of minutes shows "6h 20m" while the bar is still drawn from the
     * integer, because rounding for display and then scaling the rounded number is how a
     * bar ends up disagreeing with the label printed on it.
     */
    $points = [];

    foreach ($data as $index => $datum) {
        $value = (float) ($datum['value'] ?? 0);

        $points[] = [
            'label' => (string) ($datum['label'] ?? ''),
            'value' => $value,
            'display' => (string) ($datum['display'] ?? Formats::number($value)),
            'meta' => isset($datum['meta']) ? (string) $datum['meta'] : null,
            'color' => ChartPalette::color($datum['color'] ?? ChartPalette::seriesName($index)),
        ];
    }

    $count = count($points);
    $peak = $max !== null ? (float) $max : max(1.0, ...array_map(static fn (array $p): float => $p['value'], $points ?: [['value' => 0.0]]));
    $peak = $peak <= 0.0 ? 1.0 : $peak;

    $horizontal = $orientation === 'horizontal';

    // Geometry, in viewBox units. `padLeft` is the gutter the value axis lives in when the
    // bars are vertical, and the one the category labels live in when they are horizontal.
    // Both are named for the left because the arithmetic is done in one frame — see below.
    $vbWidth = 640;
    $rowHeight = 28;
    $vbHeight = $horizontal ? max(60, $count * $rowHeight + 8) : (int) $height;

    $padLeft = $horizontal ? 168 : 44;
    $padRight = $horizontal ? 56 : 10;
    $padTop = $horizontal ? 4 : 14;
    $padBottom = $horizontal ? 4 : 30;

    $plotWidth = $vbWidth - $padLeft - $padRight;
    $plotHeight = $vbHeight - $padTop - $padBottom;

    $band = $count > 0 ? ($horizontal ? $plotHeight / $count : $plotWidth / $count) : 0;
    $thickness = $horizontal ? min(14.0, max(6.0, $band - 12)) : min(56.0, max(4.0, $band - 10));

    /*
     * Direction.
     *
     * The SVG carries its own `dir="ltr"`, so every number above is a coordinate in one
     * stable frame whatever the page is doing: an `x` is measured from the left of the
     * viewBox, and none of the arithmetic has to know which way the reader reads.
     *
     * The mirroring is then done deliberately, on the way out — a point through `$flipX`, a
     * box through `$flipBox` — which reflects the geometry and nothing else. A `scaleX(-1)`
     * over the whole chart would take the glyphs with it and print the labels backwards.
     *
     * Text is not mirrored. It is written in the page's own direction and anchored in it:
     * `text-anchor` resolves against the inline direction of the text element, so a label
     * that is `rtl` and anchored `end` lands to the left of its point — which is exactly
     * where the reflected frame wants it, and why the anchors below are the same in both
     * directions. A figure is the exception. It stays `ltr`, because an axis value is read
     * as a number rather than as a sentence, so its anchor is the one thing flipped by hand.
     */
    $rtl = ($textDirection ?? 'ltr') === 'rtl';
    $textDir = $rtl ? 'rtl' : 'ltr';
    $flipX = static fn (float $value): float => round($rtl ? $vbWidth - $value : $value, 2);
    $flipBox = static fn (float $left, float $width): float => round($rtl ? $vbWidth - $left - $width : $left, 2);
    $figureAnchor = static fn (string $anchor): string => $rtl
        ? match ($anchor) {
            'start' => 'end',
            'end' => 'start',
            default => $anchor,
        }
        : $anchor;

    // Four gridlines is enough to read a value off and few enough to stay quiet.
    $ticks = [];
    for ($t = 0; $t <= 4; $t++) {
        $ticks[] = ['fraction' => $t / 4, 'value' => $peak * $t / 4];
    }

    $tip = array_map(static fn (array $p): array => [
        'label' => $p['label'],
        'display' => $p['display'],
        'meta' => $p['meta'],
    ], $points);
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
            this.tipY = mark.top - host.top;
            this.active = index;
        },
        hide() { this.active = null; },
     }"
     x-ref="plot">

    @if ($count === 0)
        <p class="py-6 text-center text-xs text-[var(--text-subtle)]">
            {{ $emptyLabel ?? __('Nothing to plot in this range.') }}
        </p>
    @else
        <svg viewBox="0 0 {{ $vbWidth }} {{ $vbHeight }}"
             dir="ltr"
             class="plot-ltr block h-auto w-full"
             aria-hidden="true"
             focusable="false">

            @foreach ($ticks as $tick)
                @php
                    $tickX = $horizontal ? $padLeft + $plotWidth * $tick['fraction'] : 0.0;
                    $tickY = $padTop + $plotHeight * (1 - $tick['fraction']);
                @endphp
                @if ($horizontal)
                    <line x1="{{ $flipX((float) $tickX) }}" y1="{{ $padTop }}"
                          x2="{{ $flipX((float) $tickX) }}" y2="{{ $padTop + $plotHeight }}"
                          stroke="var(--line-subtle)" stroke-width="1" />
                @else
                    <line x1="{{ $flipX((float) $padLeft) }}" y1="{{ round($tickY, 2) }}"
                          x2="{{ $flipX((float) ($vbWidth - $padRight)) }}" y2="{{ round($tickY, 2) }}"
                          stroke="var(--line-subtle)" stroke-width="1" />
                    <text x="{{ $flipX((float) ($padLeft - 8)) }}" y="{{ round($tickY + 3.5, 2) }}"
                          text-anchor="{{ $figureAnchor('end') }}" direction="ltr"
                          font-size="10" fill="var(--text-subtle)">
                        {{ $tick['value'] >= 1000 ? round($tick['value'] / 1000, 1).'k' : round($tick['value'], $peak < 10 ? 1 : 0) }}
                    </text>
                @endif
            @endforeach

            @foreach ($points as $index => $point)
                @php
                    $fraction = $point['value'] / $peak;
                    $centre = $horizontal
                        ? $padTop + $band * ($index + 0.5)
                        : $padLeft + $band * ($index + 0.5);
                    $length = max($point['value'] > 0 ? 2.0 : 0.0, $fraction * ($horizontal ? $plotWidth : $plotHeight));
                @endphp

                @if ($horizontal)
                    <text x="{{ $flipX((float) ($padLeft - 10)) }}" y="{{ round($centre + 3.5, 2) }}"
                          text-anchor="end" direction="{{ $textDir }}"
                          font-size="11" fill="var(--text-DEFAULT)">
                        {{ \Illuminate\Support\Str::limit($point['label'], 26) }}
                    </text>
                    <rect x="{{ $flipBox((float) $padLeft, (float) max(2, $plotWidth)) }}"
                          y="{{ round($centre - $thickness / 2, 2) }}"
                          width="{{ max(2, round($plotWidth, 2)) }}" height="{{ round($thickness, 2) }}"
                          rx="3" fill="var(--surface-active)" opacity="0.5" />
                    <rect x="{{ $flipBox((float) $padLeft, round($length, 2)) }}"
                          y="{{ round($centre - $thickness / 2, 2) }}"
                          width="{{ round($length, 2) }}" height="{{ round($thickness, 2) }}"
                          rx="3" fill="{{ $point['color'] }}"
                          x-on:mouseenter="show($event, {{ $index }})"
                          x-on:mouseleave="hide()" />
                    <text x="{{ $flipX((float) ($padLeft + $plotWidth + 8)) }}" y="{{ round($centre + 3.5, 2) }}"
                          text-anchor="{{ $figureAnchor('start') }}" direction="ltr"
                          font-size="10" font-weight="600" fill="var(--text-muted)">
                        {{ \Illuminate\Support\Str::limit($point['display'], 9, '') }}
                    </text>
                @else
                    <rect x="{{ $flipBox($centre - $thickness / 2, round($thickness, 2)) }}"
                          y="{{ round($padTop + $plotHeight - $length, 2) }}"
                          width="{{ round($thickness, 2) }}" height="{{ round($length, 2) }}"
                          rx="2" fill="{{ $point['color'] }}"
                          x-on:mouseenter="show($event, {{ $index }})"
                          x-on:mouseleave="hide()" />
                    @if ($count <= 16)
                        <text x="{{ $flipX($centre) }}" y="{{ $vbHeight - 10 }}"
                              text-anchor="middle" direction="{{ $textDir }}"
                              font-size="10" fill="var(--text-subtle)">
                            {{ \Illuminate\Support\Str::limit($point['label'], $count > 10 ? 5 : 9, '') }}
                        </text>
                    @endif
                @endif
            @endforeach

            @unless ($horizontal)
                <line x1="{{ $flipX((float) $padLeft) }}" y1="{{ $padTop + $plotHeight }}"
                      x2="{{ $flipX((float) ($vbWidth - $padRight)) }}" y2="{{ $padTop + $plotHeight }}"
                      stroke="var(--line-DEFAULT)" stroke-width="1" />
            @endunless
        </svg>

        <x-chart.tooltip>
            <span class="block text-2xs font-medium text-[var(--text-muted)]" x-text="items[active]?.label"></span>
            <span class="block text-xs font-semibold tabular-nums text-[var(--text-strong)]"
                  x-text="items[active]?.display"></span>
            <template x-if="items[active]?.meta">
                <span class="block text-2xs text-[var(--text-subtle)]" x-text="items[active]?.meta"></span>
            </template>
        </x-chart.tooltip>
    @endif

    <x-chart.data-table
        :caption="$caption"
        :columns="array_values(array_filter([
            $labelHeading ?? __('Category'),
            $valueHeading ?? __('Value'),
            collect($points)->contains(fn ($p) => $p['meta'] !== null) ? __('Detail') : null,
        ]))"
        :rows="collect($points)->map(fn ($p) => array_values(array_filter([
            $p['label'],
            $p['display'],
            $p['meta'],
        ], fn ($v) => $v !== null)))->all()" />
</div>
