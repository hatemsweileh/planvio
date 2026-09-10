@props([
    'groups' => [],
    'keys' => null,
    'caption' => null,
    'labelHeading' => null,
    'height' => 220,
    'orientation' => 'vertical',
    'normalise' => false,
    'legend' => true,
    'emptyLabel' => null,
])

@php
    use App\Support\ChartPalette;
    use App\Support\Formats;

    /*
     * Segment order is taken from `keys` when it is given, and from the first group when it
     * is not, so "completed" is the same colour and the same position in every column. A
     * stacked chart whose stacking order follows each group's own data is unreadable — the
     * eye compares the bottom band across columns, and the bottom band has to mean the same
     * thing each time.
     */
    $legendKeys = [];

    if (is_array($keys)) {
        foreach ($keys as $index => $key) {
            $legendKeys[] = [
                'label' => (string) ($key['label'] ?? ''),
                'color' => ChartPalette::color($key['color'] ?? ChartPalette::seriesName($index)),
            ];
        }
    } else {
        foreach (($groups[0]['segments'] ?? []) as $index => $segment) {
            $legendKeys[] = [
                'label' => (string) ($segment['label'] ?? ''),
                'color' => ChartPalette::color($segment['color'] ?? ChartPalette::seriesName($index)),
            ];
        }
    }

    $columns = [];
    $peak = 1.0;

    foreach ($groups as $group) {
        $segments = [];
        $total = 0.0;

        foreach (array_values($group['segments'] ?? []) as $index => $segment) {
            $value = max(0.0, (float) ($segment['value'] ?? 0));
            $total += $value;

            $segments[] = [
                'label' => (string) ($segment['label'] ?? ($legendKeys[$index]['label'] ?? '')),
                'value' => $value,
                'display' => (string) ($segment['display'] ?? Formats::number($value)),
                'color' => ChartPalette::color(
                    $segment['color'] ?? ($legendKeys[$index]['color'] ?? ChartPalette::seriesName($index)),
                ),
            ];
        }

        $columns[] = [
            'label' => (string) ($group['label'] ?? ''),
            'meta' => isset($group['meta']) ? (string) $group['meta'] : null,
            'segments' => $segments,
            'total' => $total,
            'totalDisplay' => (string) ($group['display'] ?? Formats::number($total)),
        ];

        $peak = max($peak, $total);
    }

    $count = count($columns);
    $horizontal = $orientation === 'horizontal';

    $vbWidth = 640;
    $rowHeight = 30;
    $vbHeight = $horizontal ? max(60, $count * $rowHeight + 8) : (int) $height;

    $padLeft = $horizontal ? 168 : 44;
    $padRight = $horizontal ? 56 : 10;
    $padTop = $horizontal ? 4 : 14;
    $padBottom = $horizontal ? 4 : 30;

    $plotWidth = $vbWidth - $padLeft - $padRight;
    $plotHeight = $vbHeight - $padTop - $padBottom;

    $band = $count > 0 ? ($horizontal ? $plotHeight / $count : $plotWidth / $count) : 0;
    $thickness = $horizontal ? min(16.0, max(6.0, $band - 12)) : min(56.0, max(4.0, $band - 12));

    /*
     * Direction. As in `chart.bar`: the SVG carries its own `dir="ltr"` so the geometry is
     * computed once in a single frame, and each coordinate is reflected on the way out. In
     * an RTL page the stack therefore grows from the right, the first category sits on the
     * right, and the value axis moves to the right with them — while the labels are written
     * in the page's own direction and keep their logical anchors, because `text-anchor`
     * resolves against the inline direction of the text element. Only the figures, which
     * stay `ltr`, need their anchor flipped by hand.
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

    $ticks = [];
    if (! $normalise) {
        for ($t = 0; $t <= 4; $t++) {
            $ticks[] = ['fraction' => $t / 4, 'value' => $peak * $t / 4];
        }
    }

    $tip = [];
    foreach ($columns as $column) {
        $tip[] = [
            'label' => $column['label'],
            'total' => $column['totalDisplay'],
            'meta' => $column['meta'],
            'rows' => array_values(array_map(static fn (array $s): array => [
                'label' => $s['label'],
                'display' => $s['display'],
                'color' => $s['color'],
            ], array_filter($column['segments'], static fn (array $s): bool => $s['value'] > 0))),
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
                @if ($horizontal)
                    @php $x = $padLeft + $plotWidth * $tick['fraction']; @endphp
                    <line x1="{{ $flipX((float) $x) }}" y1="{{ $padTop }}"
                          x2="{{ $flipX((float) $x) }}" y2="{{ $padTop + $plotHeight }}"
                          stroke="var(--line-subtle)" stroke-width="1" />
                @else
                    @php $y = $padTop + $plotHeight * (1 - $tick['fraction']); @endphp
                    <line x1="{{ $flipX((float) $padLeft) }}" y1="{{ round($y, 2) }}"
                          x2="{{ $flipX((float) ($vbWidth - $padRight)) }}" y2="{{ round($y, 2) }}"
                          stroke="var(--line-subtle)" stroke-width="1" />
                    <text x="{{ $flipX((float) ($padLeft - 8)) }}" y="{{ round($y + 3.5, 2) }}"
                          text-anchor="{{ $figureAnchor('end') }}" direction="ltr"
                          font-size="10" fill="var(--text-subtle)">
                        {{ $tick['value'] >= 1000 ? round($tick['value'] / 1000, 1).'k' : round($tick['value'], $peak < 10 ? 1 : 0) }}
                    </text>
                @endif
            @endforeach

            @foreach ($columns as $index => $column)
                @php
                    $scale = $normalise
                        ? ($column['total'] > 0 ? 1 / $column['total'] : 0.0)
                        : 1 / $peak;
                    $centre = $horizontal
                        ? $padTop + $band * ($index + 0.5)
                        : $padLeft + $band * ($index + 0.5);
                    $cursor = 0.0;
                @endphp

                @if ($horizontal)
                    <text x="{{ $flipX((float) ($padLeft - 10)) }}" y="{{ round($centre + 3.5, 2) }}"
                          text-anchor="end" direction="{{ $textDir }}"
                          font-size="11" fill="var(--text-DEFAULT)">
                        {{ \Illuminate\Support\Str::limit($column['label'], 26) }}
                    </text>
                    <rect x="{{ $flipBox((float) $padLeft, round((float) $plotWidth, 2)) }}"
                          y="{{ round($centre - $thickness / 2, 2) }}"
                          width="{{ round($plotWidth, 2) }}" height="{{ round($thickness, 2) }}"
                          rx="3" fill="var(--surface-active)" opacity="0.5"
                          x-on:mouseenter="show($event, {{ $index }})"
                          x-on:mouseleave="hide()" />
                @endif

                @foreach ($column['segments'] as $segment)
                    @php
                        $length = $segment['value'] * $scale * ($horizontal ? $plotWidth : $plotHeight);
                        $start = $cursor;
                        $cursor += $length;
                    @endphp
                    @continue($length <= 0)

                    @if ($horizontal)
                        <rect x="{{ $flipBox($padLeft + $start, round($length, 2)) }}"
                              y="{{ round($centre - $thickness / 2, 2) }}"
                              width="{{ round($length, 2) }}" height="{{ round($thickness, 2) }}"
                              fill="{{ $segment['color'] }}"
                              x-on:mouseenter="show($event, {{ $index }})"
                              x-on:mouseleave="hide()" />
                    @else
                        <rect x="{{ $flipBox($centre - $thickness / 2, round($thickness, 2)) }}"
                              y="{{ round($padTop + $plotHeight - $start - $length, 2) }}"
                              width="{{ round($thickness, 2) }}" height="{{ round($length, 2) }}"
                              fill="{{ $segment['color'] }}"
                              x-on:mouseenter="show($event, {{ $index }})"
                              x-on:mouseleave="hide()" />
                    @endif
                @endforeach

                @if ($horizontal)
                    <text x="{{ $flipX((float) ($padLeft + $plotWidth + 8)) }}" y="{{ round($centre + 3.5, 2) }}"
                          text-anchor="{{ $figureAnchor('start') }}" direction="ltr"
                          font-size="10" font-weight="600" fill="var(--text-muted)">
                        {{ \Illuminate\Support\Str::limit($column['totalDisplay'], 9, '') }}
                    </text>
                @elseif ($count <= 16)
                    <text x="{{ $flipX($centre) }}" y="{{ $vbHeight - 10 }}"
                          text-anchor="middle" direction="{{ $textDir }}"
                          font-size="10" fill="var(--text-subtle)">
                        {{ \Illuminate\Support\Str::limit($column['label'], $count > 10 ? 5 : 9, '') }}
                    </text>
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
            <template x-for="row in (items[active]?.rows ?? [])" :key="row.label">
                <span class="mt-0.5 flex items-center gap-1.5">
                    <span class="size-1.5 rounded-full" :style="`background-color: ${row.color}`"></span>
                    <span class="text-2xs text-[var(--text-muted)]" x-text="row.label"></span>
                    <span class="text-xs font-semibold tabular-nums text-[var(--text-strong)]" x-text="row.display"></span>
                </span>
            </template>
        </x-chart.tooltip>

        @if ($legend && $legendKeys !== [])
            <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
                @foreach ($legendKeys as $key)
                    <span class="inline-flex items-center gap-1.5 text-2xs text-[var(--text-muted)]">
                        <span class="size-2 rounded-sm" style="background-color: {{ $key['color'] }}"></span>
                        {{ $key['label'] }}
                    </span>
                @endforeach
            </div>
        @endif
    @endif

    <x-chart.data-table
        :caption="$caption"
        :columns="array_merge(
            [$labelHeading ?? __('Category')],
            collect($legendKeys)->pluck('label')->all(),
            [__('Total')],
        )"
        :rows="collect($columns)->map(fn ($column) => array_merge(
            [$column['label']],
            collect($legendKeys)->map(fn ($key, $i) => (string) ($column['segments'][$i]['display'] ?? '0'))->all(),
            [$column['totalDisplay']],
        ))->all()" />
</div>
