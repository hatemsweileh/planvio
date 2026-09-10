@props([
    'caption' => null,
    'columns' => [],
    'rows' => [],
])

{{--
    The numbers behind a chart, for anyone the chart itself cannot reach.

    Every chart in Planvio is decorative markup — `aria-hidden` SVG — paired with this
    table. A screen reader, a text browser, a page saved to a text file and a person who
    turned images off all get the figures rather than an alt string that summarises them
    away. It is also the honest fallback when the SVG fails to paint at all.

    Visually hidden rather than merely `display:none`: hidden content is not read out,
    which would defeat the whole point.

    It carries no direction of its own, deliberately. The plot beside it pins itself to a
    left-to-right coordinate frame and mirrors its geometry by hand; this is a table of
    words and figures, so it inherits the page — right to left in Arabic, with the row
    header column on the right — exactly like every other table in the product.
--}}
<table class="sr-only" {{ $attributes }}>
    @if ($caption)
        <caption>{{ $caption }}</caption>
    @endif
    <thead>
        <tr>
            @foreach ($columns as $column)
                <th scope="col">{{ $column }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($rows as $row)
            <tr>
                @foreach (array_values($row) as $index => $cell)
                    @if ($index === 0)
                        <th scope="row">{{ $cell }}</th>
                    @else
                        <td>{{ $cell }}</td>
                    @endif
                @endforeach
            </tr>
        @endforeach
    </tbody>
</table>
