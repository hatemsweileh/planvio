{{--
    AI usage. Counts only — see the note at the foot of the page and the docblock on
    App\Ai\Usage\UsageReporter for why there is no currency figure anywhere on it.
--}}
@php
    $muted = 'color: var(--fi-color-gray-500, #6b7280);';
    $cell = 'padding: 0.5rem 0.75rem; text-align: end; font-variant-numeric: tabular-nums;';
    $cellStart = 'padding: 0.5rem 0.75rem; text-align: start;';

    /*
     | An eyebrow is upper-cased and tracked out in Latin. Arabic has no letter case and is
     | joined, so tracking stretches the joins and pulls the word apart — the decision
     | resources/css/app.css makes with `:lang(ar)`, made here in PHP because these are
     | inline styles, which no stylesheet rule can out-specify.
     */
    $eyebrow = str_starts_with(app()->getLocale(), 'ar')
        ? 'font-size: 0.75rem;'
        : 'font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em;';

    $head = 'padding: 0.5rem 0.75rem; font-weight: 500;'.$eyebrow.$muted;
    $peak = collect($byDay)->max('tokens_total') ?: 1;
@endphp

<x-filament-panels::page>
    <x-filament::section
        :heading="__('Period')"
        {{--
            `toFormattedDateString()` is `format()` underneath, and PHP's own formatter
            answers in English whatever the application locale is — which is how two English
            month names ended up inside an otherwise Arabic sentence. `isoFormat()` is the
            locale-aware half of the pair and follows App::setLocale through Carbon.
        --}}
        :description="__('Counted from the daily rollup, :from to :to.', ['from' => $from->isoFormat('ll'), 'to' => $to->isoFormat('ll')])"
    >
        <div style="display: flex; flex-wrap: wrap; gap: 1rem; align-items: center;">
            <x-filament::button.group>
                @foreach ($windows as $window)
                    <x-filament::button
                        :color="$days === $window ? 'primary' : 'gray'"
                        :outlined="$days !== $window"
                        size="sm"
                        wire:click="$set('days', {{ $window }})"
                    >
                        {{ trans_choice('{1} 1 day|[2,*] :count days', $window, ['count' => $window]) }}
                    </x-filament::button>
                @endforeach
            </x-filament::button.group>

            <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.875rem;">
                <span style="{{ $muted }}">{{ __('Workspace') }}</span>
                <select
                    wire:model.live="workspaceId"
                    class="fi-input"
                    style="padding: 0.375rem 0.625rem; border-radius: 0.5rem; border: 1px solid rgba(127, 127, 127, 0.35); background: transparent; color: inherit;"
                >
                    <option value="">{{ __('Every workspace') }}</option>
                    @foreach ($workspaces as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </x-filament::section>

    <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));">
        @foreach ([
            __('Runs') => $summary['runs'],
            __('Tool calls') => $summary['tool_calls'],
            __('Tokens in') => $summary['tokens_in'],
            __('Tokens out') => $summary['tokens_out'],
            __('Tokens total') => $summary['tokens_total'],
            __('Errors') => $summary['errors'],
        ] as $label => $number)
            <x-filament::section compact>
                <p style="{{ $eyebrow }} {{ $muted }}">{{ $label }}</p>
                <p style="font-size: 1.5rem; font-weight: 600; font-variant-numeric: tabular-nums; margin-top: 0.25rem;">
                    {{ number_format($number) }}
                </p>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section
        :heading="__('Tokens per day')"
        :description="__('Days with no activity are absent rather than drawn as zero — a gap is a gap, not a quiet day.')"
    >
        @if (count($byDay) === 0)
            <p style="font-size: 0.875rem; {{ $muted }}">{{ __('Nothing was recorded in this period.') }}</p>
        @else
            <div style="display: flex; align-items: flex-end; gap: 2px; height: 8rem;">
                @foreach ($byDay as $day)
                    <div
                        title="{{ $day['date'] }} — {{ number_format($day['tokens_total']) }} {{ __('tokens') }}, {{ number_format($day['runs']) }} {{ __('runs') }}"
                        style="flex: 1 1 0; min-width: 2px; border-radius: 2px 2px 0 0; background-color: var(--fi-color-primary-500, #3f66b0); height: {{ max(2, (int) round(($day['tokens_total'] / $peak) * 100)) }}%;"
                    ></div>
                @endforeach
            </div>
            <p style="margin-top: 0.5rem; font-size: 0.75rem; {{ $muted }}">
                {{ __('Peak day: :count tokens.', ['count' => number_format($peak)]) }}
            </p>
        @endif
    </x-filament::section>

    <div style="display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(24rem, 1fr));">
        @if (count($byWorkspace) > 0)
            <x-filament::section :heading="__('By workspace')">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} text-align: start;">{{ __('Workspace') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Runs') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Tokens') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byWorkspace as $row)
                            <tr style="border-top: 1px solid rgba(127, 127, 127, 0.2);">
                                <td style="{{ $cellStart }}">{{ $row['label'] }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['runs']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['tokens_total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        <x-filament::section
            :heading="__('By model')"
            :description="__('The breakdown to apply your own per-model rates to.')"
        >
            @if (count($byModel) === 0)
                <p style="font-size: 0.875rem; {{ $muted }}">{{ __('Nothing was recorded in this period.') }}</p>
            @else
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} text-align: start;">{{ __('Model') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('In') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Out') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Runs') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byModel as $row)
                            <tr style="border-top: 1px solid rgba(127, 127, 127, 0.2);">
                                <td style="{{ $cellStart }}">{{ $row['label'] }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['tokens_in']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['tokens_out']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['runs']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('By person')">
            @if (count($byUser) === 0)
                <p style="font-size: 0.875rem; {{ $muted }}">{{ __('Nothing was recorded in this period.') }}</p>
            @else
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} text-align: start;">{{ __('Person') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Runs') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Tokens') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Errors') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byUser as $row)
                            <tr style="border-top: 1px solid rgba(127, 127, 127, 0.2);">
                                <td style="{{ $cellStart }}">{{ $row['label'] }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['runs']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['tokens_total']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['errors']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('By provider')">
            @if (count($byProvider) === 0)
                <p style="font-size: 0.875rem; {{ $muted }}">{{ __('Nothing was recorded in this period.') }}</p>
            @else
                <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                    <thead>
                        <tr>
                            <th style="{{ $head }} text-align: start;">{{ __('Provider') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Runs') }}</th>
                            <th style="{{ $head }} text-align: end;">{{ __('Tokens') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($byProvider as $row)
                            <tr style="border-top: 1px solid rgba(127, 127, 127, 0.2);">
                                <td style="{{ $cellStart }}">{{ $row['label'] }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['runs']) }}</td>
                                <td style="{{ $cell }}">{{ number_format($row['tokens_total']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section :heading="__('Why there is no cost figure here')" icon="heroicon-o-information-circle">
        <div style="display: grid; gap: 0.625rem; font-size: 0.875rem; {{ $muted }}">
            <p>
                {{ __('Providers do not return a price with a completion. Rates change without notice, differ by contract and region, and depend on whether a token was an input, an output or a reasoning token — and a self-hosted Planvio may be pointed at a local endpoint where the marginal cost is electricity.') }}
            </p>
            <p>
                {{ __('A number with a currency symbol in front of it would therefore be a guess dressed as a fact, and it would be believed: it would end up in a budget and be reconciled against an invoice it does not match. So Planvio reports what it actually knows, and the per-model breakdown above is there for you to apply your own rates to.') }}
            </p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
