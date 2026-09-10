{{--
    Failed jobs. Written as a widget view rather than a table widget because `failed_jobs` has no
    Eloquent model, and inventing one would put a table outside the normative schema into
    app/Models for the sake of a dashboard panel.
--}}
@php
    $muted = 'color: var(--fi-color-gray-500, #6b7280);';
@endphp

<x-filament-widgets::widget>
    <x-filament::section
        :heading="__('Failed jobs')"
        :description="__('Queued work that gave up. Nothing retries it on its own.')"
        icon="heroicon-o-exclamation-triangle"
        :icon-color="($count ?? 0) > 0 ? 'danger' : 'gray'"
    >
        <x-slot name="afterHeader">
            @if ($supported)
                <x-filament::badge :color="($count ?? 0) > 0 ? 'danger' : 'success'">
                    {{ number_format($count ?? 0) }}
                </x-filament::badge>
            @endif
        </x-slot>

        @if (! $supported)
            <p style="font-size: 0.875rem; {{ $muted }}">
                {{ __('Failed jobs are not being stored: QUEUE_FAILED_DRIVER is set to something other than the database. Nothing can be listed or retried from here.') }}
            </p>
        @elseif ($count === null)
            <p style="font-size: 0.875rem; {{ $muted }}">
                {{ __('The failed jobs table could not be read. It is created by the migrations.') }}
            </p>
        @elseif ($count === 0)
            <p style="font-size: 0.875rem; {{ $muted }}">
                {{ __('Nothing has failed. Email, webhooks and queued AI work are all completing.') }}
            </p>
        @else
            <ul style="display: grid; gap: 0.75rem;">
                @foreach ($jobs as $job)
                    <li style="display: grid; gap: 0.125rem; padding-bottom: 0.75rem; border-bottom: 1px solid rgba(127, 127, 127, 0.2);">
                        <div style="display: flex; justify-content: space-between; gap: 0.75rem; align-items: baseline;">
                            <span style="font-weight: 500;">{{ $job['job'] }}</span>
                            <span style="font-size: 0.75rem; white-space: nowrap; {{ $muted }}">
                                {{ $job['failed_at']?->diffForHumans() ?? __('Unknown time') }}
                            </span>
                        </div>
                        <p style="font-size: 0.8125rem; word-break: break-word; {{ $muted }}">{{ $job['reason'] }}</p>
                        <p style="font-size: 0.75rem; {{ $muted }}">{{ __('Queue: :queue', ['queue' => $job['queue']]) }}</p>
                    </li>
                @endforeach
            </ul>

            @if ($count > count($jobs))
                <p style="margin-top: 0.75rem; font-size: 0.8125rem; {{ $muted }}">
                    {{ __('And :count more.', ['count' => number_format($count - count($jobs))]) }}
                </p>
            @endif
        @endif

        @if ($supported && ($count ?? 0) > 0)
            <x-slot name="footer">
                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
                    <x-filament::button
                        size="sm"
                        color="primary"
                        wire:click="retryAll"
                        wire:confirm="{{ __('Push all :count failed jobs back onto the queue? Work that partly succeeded will be attempted again — a webhook receiver may see the same delivery twice.', ['count' => number_format($count)]) }}"
                        wire:loading.attr="disabled"
                    >
                        {{ __('Retry all') }}
                    </x-filament::button>

                    <x-filament::button
                        size="sm"
                        color="danger"
                        outlined
                        wire:click="deleteAll"
                        wire:confirm="{{ __('Discard all :count failed jobs? The work they represented is gone and nothing will retry it. This cannot be undone.', ['count' => number_format($count)]) }}"
                        wire:loading.attr="disabled"
                    >
                        {{ __('Discard all') }}
                    </x-filament::button>
                </div>
            </x-slot>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
