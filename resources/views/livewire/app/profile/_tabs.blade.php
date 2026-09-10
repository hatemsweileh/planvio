{{-- Shared header for the three account screens, so they read as one place with three tabs. --}}
<div>
    <h2 class="text-base font-semibold tracking-tight text-[var(--text-strong)]">{{ __('Your account') }}</h2>
    <p class="mt-0.5 text-xs text-[var(--text-muted)]">{{ auth()->user()->email }}</p>

    <x-ui.tabs class="mt-4">
        <x-ui.tab :href="route('profile.edit')" :active="request()->routeIs('profile.edit')" icon="icon.cog">
            {{ __('Profile') }}
        </x-ui.tab>
        <x-ui.tab :href="route('profile.notifications')" :active="request()->routeIs('profile.notifications')" icon="icon.bell">
            {{ __('Notifications') }}
        </x-ui.tab>
        <x-ui.tab :href="route('profile.security')" :active="request()->routeIs('profile.security')" icon="icon.shield">
            {{ __('Security') }}
        </x-ui.tab>
    </x-ui.tabs>
</div>
