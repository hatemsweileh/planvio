@props([
    'labels' => [],
    'series' => [],
    'caption' => null,
    'labelHeading' => null,
    'height' => 200,
    'area' => true,
    'emptyLabel' => null,
])

@php
    use App\Support\ChartPalette;
    use App\Support\Formats;

    $labels = array_values(array_map(static fn ($label): string => (string) $label, $labels));
    $count = count($labels);

    /*
     * Series are normalised to the length of the label axis. A series shorter than the axis
     * is padded with zeros rather than drawn short: a line that stops halfway across reads
     * as "the work stopped", not as "we have no figure for those days".
     */
    $lines = [];

    foreach ($series as $index => $entry) {
        $values = array_values(array_map(static fn ($v): float => (float) $v, $entry['values'] ?? []));
        $values = array_pad(array_slice($values, 0, $count), $count, 0.0);

        $lines[] = [
            'label' => (string) ($entry['label'] ?? ''),
            'values' => $values,
            'display' => array_values($entry['display'] ?? []),
            'color' => ChartPalette::color($entry['color'] ?? ChartPalette::seriesName($index)),
        ];
    }

    $peak = 1.0;
    foreach ($lines as $line) {
        foreach ($line['values'] as $value) {
            $peak = max($peak, $value);
        }
    }

    $vbWidth = 640;
    $vbHeight = (int) $height;
    $padLeft = 44;
    $padRight = 12;
    $padTop = 14;
    $padBottom = 26;
    $plotWidth = $vbWidth - $padLeft - $padRight;
    $plotHeight = $vbHeight - $padTop - $padBottom;

    /*
     * Direction. The SVG carries its own `dir="ltr"`, so the arithmetic below happens once,
     * in one frame, with the first day of the range at `x = padLeft`; the reflection into
     * the reader's direction is applied at the moment a coordinate is written out. In an
     * RTL page that puts the earliest day on the right and the value axis on the right with
     * it, so the line is read the way the page is read.
     *
     * The reflection is of the geometry only. Labels are written in the page's direction
     * and anchored in it — `text-anchor` resolves against the inline direction of the text
     * element, so the anchors are unchanged between the two — while the axis figures stay
     * `ltr` and have their anchor flipped by hand. Mirroring the whole plot with a
     * `scaleX(-1)` would reverse the glyphs along with the plot.
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

    $xAt = static fn (int $i): float => $count < 2
        ? $padLeft + $plotWidth / 2
        : $padLeft + $plotWidth * ($i / ($count - 1));
    $yAt = static fn (float $v): float => $padTop + $plotHeight * (1 - ($v / $peak));

    foreach ($lines as $key => $line) {
        $path = [];
        foreach ($line['values'] as $i => $value) {
            $path[] = $flipX($xAt($i)).','.round($yAt($value), 2);
        }
        $lines[$key]['path'] = implode(' ', $path);
        $lines[$key]['fill'] = $count > 0
            ? $flipX($xAt(0)).','.round($padTop + $plotHeight, 2).' '
                .implode(' ', $path).' '
                .$flipX($xAt($count - 1)).','.round($padTop + $plotHeight, 2)
            : '';
    }

    $ticks = [];
    for ($t = 0; $t <= 4; $t++) {
        $ticks[] = ['fraction' => $t / 4, 'value' => $peak * $t / 4];
    }

    // At most eight printed x labels, evenly spaced: a month of days would otherwise
    // collapse into an unreadable smear.
    $labelStride = $count <= 8 ? 1 : (int) ceil($count / 8);

    $tip = [];
    foreach ($labels as $i => $label) {
        $tip[] = [
            'label' => $label,
            'rows' => array_map(static fn (array $line): array => [
                'label' => $line['label'],
                'color' => $line['color'],
                'display' => (string) ($line['display'][$i] ?? Formats::number($line['values'][$i])),
            ], $lines),
        ];
    }
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
            this.tipY = mark.top - host.top + 24;
            this.active = index;
        },
        hide() { this.active = null; },
     }"
     x-ref="plot">

    @if ($count === 0 || $lines === [])
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
                @php $y = $padTop + $plotHeight * (1 - $tick['fraction']); @endphp
                <line x1="{{ $flipX((float) $padLeft) }}" y1="{{ round($y, 2) }}"
                      x2="{{ $flipX((float) ($vbWidth - $padRight)) }}" y2="{{ round($y, 2) }}"
                      stroke="var(--line-subtle)" stroke-width="1" />
                <text x="{{ $flipX((float) ($padLeft - 8)) }}" y="{{ round($y + 3.5, 2) }}"
                      text-anchor="{{ $figureAnchor('end') }}" direction="ltr"
                      font-size="10" fill="var(--text-subtle)">
                    {{ $tick['value'] >= 1000 ? round($tick['value'] / 1000, 1).'k' : round($tick['value'], $peak < 10 ? 1 : 0) }}
                </text>
            @endforeach

            @if ($area && count($lines) === 1)
                <polygon points="{{ $lines[0]['fill'] }}"
                         fill="{{ $lines[0]['color'] }}" opacity="0.12" />
            @endif

            @foreach ($lines as $line)
                <polyline points="{{ $line['path'] }}"
                          fill="none" stroke="{{ $line['color'] }}"
                          stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                @if ($count <= 40)
                    @foreach ($line['values'] as $i => $value)
                        <circle cx="{{ $flipX($xAt($i)) }}" cy="{{ round($yAt($value), 2) }}"
                                r="2.5" fill="var(--surface-panel)"
                                stroke="{{ $line['color'] }}" stroke-width="1.75" />
                    @endforeach
                @endif
            @endforeach

            {{-- Full-height hit zones: pointing anywhere in a column reads that day. --}}
            @foreach ($labels as $i => $label)
                @php
                    $slotWidth = $count < 2 ? $plotWidth : $plotWidth / ($count - 1);
                    $left = max($padLeft, $xAt($i) - $slotWidth / 2);
                    $zoneWidth = round(min($slotWidth, $vbWidth - $padRight - $left), 2);
                @endphp
                <rect x="{{ $flipBox($left, $zoneWidth) }}" y="{{ $padTop }}"
                      width="{{ $zoneWidth }}"
                      height="{{ $plotHeight }}"
                      fill="transparent"
                      x-on:mouseenter="show($event, {{ $i }})"
                      x-on:mouseleave="hide()" />
                @if ($i % $labelStride === 0)
                    <text x="{{ $flipX($xAt($i)) }}" y="{{ $vbHeight - 8 }}"
                          text-anchor="middle" direction="{{ $textDir }}"
                          font-size="10" fill="var(--text-subtle)">
                        {{ $label }}
                    </text>
                @endif
            @endforeach

            <line x1="{{ $flipX((float) $padLeft) }}" y1="{{ $padTop + $plotHeight }}"
                  x2="{{ $flipX((float) ($vbWidth - $padRight)) }}" y2="{{ $padTop + $plotHeight }}"
                  stroke="var(--line-DEFAULT)" stroke-width="1" />
        </svg>

        <x-chart.tooltip>
            <span class="block text-2xs font-medium text-[var(--text-muted)]" x-text="items[active]?.label"></span>
            <template x-for="row in (items[active]?.rows ?? [])" :key="row.label">
                <span class="mt-0.5 flex items-center gap-1.5">
                    <span class="size-1.5 rounded-full" :style="`background-color: ${row.color}`"></span>
                    <span class="text-2xs text-[var(--text-muted)]" x-text="row.label"></span>
                    <span class="text-xs font-semibold tabular-nums text-[var(--text-strong)]" x-text="row.display"></span>
                </span>
            </template>
        </x-chart.tooltip>

        @if (count($lines) > 1)
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                @foreach ($lines as $line)
                    <span class="inline-flex items-center gap-1.5 text-2xs text-[var(--text-muted)]">
                        <span class="size-2 rounded-full" style="background-color: {{ $line['color'] }}"></span>
                        {{ $line['label'] }}
                    </span>
                @endforeach
            </div>
        @endif
    @endif

    <x-chart.data-table
        :caption="$caption"
        :columns="array_merge([$labelHeading ?? __('Date')], collect($lines)->pluck('label')->all())"
        :rows="collect($labels)->map(fn ($label, $i) => array_merge(
            [$label],
            collect($lines)->map(fn ($line) => (string) ($line['display'][$i] ?? Formats::number($line['values'][$i])))->all(),
        ))->all()" />
</div>
