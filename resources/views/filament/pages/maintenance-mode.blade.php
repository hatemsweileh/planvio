{{--
    Maintenance mode. The state is the whole page: what visitors see right now, and who is
    exempt from it.
--}}
@php
    $muted = 'color: var(--fi-color-gray-500, #6b7280);';
@endphp

<x-filament-panels::page>
    <x-filament::section
        :icon="$engaged ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'"
        :icon-color="$engaged ? 'warning' : 'success'"
        :heading="$engaged ? __('Planvio is closed') : __('Planvio is open')"
        :description="$engaged
            ? __('Everybody except platform administrators is being shown the maintenance page.')
            : __('Everybody with an account can sign in and work normally.')"
    >
        <x-slot name="afterHeader">
            <x-filament::badge :color="$engaged ? 'warning' : 'success'">
                {{ $engaged ? __('Engaged') : __('Off') }}
            </x-filament::badge>
        </x-slot>

        <div style="display: grid; gap: 1rem;">
            <div>
                <p style="font-size: 0.875rem; margin-bottom: 0.5rem; {{ $muted }}">
                    {{ $engaged ? __('What visitors are seeing:') : __('What visitors would see:') }}
                </p>

                <blockquote style="padding: 0.875rem 1rem; border-inline-start: 3px solid rgba(127, 127, 127, 0.35); border-radius: 0.375rem; background-color: rgba(127, 127, 127, 0.08);">
                    {{ $message ?? $defaultMessage }}
                    @unless ($message)
                        <span style="{{ $muted }} font-size: 0.875rem;">&nbsp;({{ __('default') }})</span>
                    @endunless
                </blockquote>
            </div>

            <ul style="display: grid; gap: 0.375rem; font-size: 0.875rem; {{ $muted }}">
                <li>{{ __('Platform administrators keep full access, including this panel.') }}</li>
                <li>{{ __('The sign-in, password-reset and two-factor screens stay open, so an administrator can get back in from any browser.') }}</li>
                <li>{{ __('The installer and the /up health endpoint stay reachable.') }}</li>
                <li>{{ __('The API and Livewire both answer 503, so nothing half-submits while the site is closed.') }}</li>
                <li>{{ __('Scheduled work and the queue are unaffected: cron keeps running.') }}</li>
            </ul>
        </div>
    </x-filament::section>
</x-filament-panels::page>
