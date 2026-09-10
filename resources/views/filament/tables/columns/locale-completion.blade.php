{{--
    How much of the catalogue a language can render.

    Inline styles rather than utility classes: this view is rendered inside the Filament panel,
    which does not load the product's Tailwind build (resources/css/app.css). The panel's own
    CSS variables are used where there is one, with a literal fallback for the case where the
    theme has not defined it.
--}}
@php
    $percent = max(0.0, min(100.0, (float) ($percent ?? 0)));
    $total = (int) ($total ?? 0);
    $translated = (int) ($translated ?? 0);

    $colour = match (true) {
        $percent >= 99.5 => 'var(--fi-color-success-500, #10b981)',
        $percent >= 60 => 'var(--fi-color-primary-500, #5379c1)',
        $percent >= 20 => 'var(--fi-color-warning-500, #f59e0b)',
        default => 'var(--fi-color-danger-500, #ef4444)',
    };
@endphp

<div style="min-width: 9rem; max-width: 14rem;">
    <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; font-size: 0.75rem; font-variant-numeric: tabular-nums;">
        <span style="font-weight: 600;">{{ number_format($percent, 1) }}%</span>
        <span style="color: var(--fi-color-gray-500, #6b7280);">
            {{ trans_choice('{0} nothing yet|{1} 1 of :total|[2,*] :count of :total', $translated, [
                'count' => number_format($translated),
                'total' => number_format($total),
            ]) }}
        </span>
    </div>

    <div
        role="progressbar"
        aria-valuenow="{{ (int) round($percent) }}"
        aria-valuemin="0"
        aria-valuemax="100"
        style="margin-top: 0.3rem; height: 0.375rem; border-radius: 9999px; overflow: hidden; background: rgba(127, 127, 127, 0.22);"
    >
        <div style="height: 100%; width: {{ $percent }}%; background: {{ $colour }};"></div>
    </div>
</div>
