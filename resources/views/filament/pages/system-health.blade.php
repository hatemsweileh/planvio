{{--
    System Health.

    Every check states its evidence underneath the verdict, because a verdict on its own asks to
    be trusted and this page has to be checkable. See App\Filament\Support\HealthReport.
--}}
@php
    $muted = 'color: var(--fi-color-gray-500, #6b7280);';
@endphp

<x-filament-panels::page>
    <x-filament::section
        :icon="$overall->icon()"
        :icon-color="$overall->color()"
        :heading="match ($overall->value) {
            'healthy' => __('Everything checks out'),
            'warning' => __('Working, with things worth fixing'),
            default => __('Something is broken'),
        }"
        :description="match ($overall->value) {
            'healthy' => __('All nine checks passed, each on evidence gathered just now.'),
            'warning' => __('Nothing is down, but at least one check found something that will cause trouble or could not be verified.'),
            default => __('At least one check failed. Start with the red ones below.'),
        }"
    />

    <div style="display: grid; gap: 1rem;">
        @foreach ($checks as $check)
            <x-filament::section
                :icon="$check->status->icon()"
                :icon-color="$check->status->color()"
                :heading="$check->label"
                :description="$check->summary"
            >
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$check->status->color()">
                        {{ $check->status->label() }}
                    </x-filament::badge>
                </x-slot>

                @if (count($check->evidence) > 0)
                    <ul style="display: grid; gap: 0.375rem; font-size: 0.875rem; {{ $muted }}">
                        @foreach ($check->evidence as $line)
                            <li style="display: flex; gap: 0.5rem;">
                                <span aria-hidden="true">&middot;</span>
                                <span style="word-break: break-word;">{{ $line }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p style="font-size: 0.875rem; {{ $muted }}">
                        {{ __('Nothing was observed for this check.') }}
                    </p>
                @endif

                @if ($check->remedy)
                    <x-slot name="footer">
                        <p style="font-size: 0.875rem;">
                            <strong>{{ __('What to do:') }}</strong>
                            {{ $check->remedy }}
                        </p>
                    </x-slot>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
