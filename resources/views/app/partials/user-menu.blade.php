@php($me = auth()->user())

<x-ui.dropdown align="end" width="w-60">
    <x-slot:trigger>
        <button type="button"
                class="grid size-8 place-items-center rounded-md transition-colors hover:bg-[var(--surface-hover)]"
                aria-label="{{ __('Account menu') }}">
            <x-ui.avatar :user="$me" size="md" />
        </button>
    </x-slot:trigger>

    <div class="flex items-center gap-2.5 px-2 py-2">
        <x-ui.avatar :user="$me" size="lg" />
        <div class="min-w-0">
            <p dir="auto" class="truncate text-sm font-medium text-[var(--text-strong)]">{{ $me->name }}</p>
            <p dir="auto" class="truncate text-xs text-[var(--text-muted)]">{{ $me->email }}</p>
        </div>
    </div>

    <x-ui.dropdown-separator />

    <x-ui.dropdown-item :href="route('profile.edit')" icon="icon.cog">{{ __('Profile settings') }}</x-ui.dropdown-item>
    <x-ui.dropdown-item :href="route('profile.notifications')" icon="icon.bell">{{ __('Notifications') }}</x-ui.dropdown-item>
    <x-ui.dropdown-item :href="route('profile.security')" icon="icon.shield">{{ __('Security') }}</x-ui.dropdown-item>

    <x-ui.dropdown-separator />

    {{--
        Three-state theme control. The active state is driven by Alpine rather than the
        server because the choice lives in localStorage and applies before first paint.
    --}}
    <div x-data="themeToggle" class="px-2 py-1.5">
        <p class="pb-1.5 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Appearance') }}
        </p>
        <div class="flex items-center gap-0.5 rounded-md bg-[var(--surface-sunken)] p-0.5">
            @foreach ([['light', __('Light'), 'icon.sun'], ['dark', __('Dark'), 'icon.moon'], ['system', __('System'), 'icon.monitor']] as [$value, $label, $icon])
                <button type="button" x-on:click="set('{{ $value }}')"
                        :class="preference === '{{ $value }}'
                            ? 'bg-[var(--surface-panel)] text-[var(--text-strong)] shadow-xs'
                            : 'text-[var(--text-muted)] hover:text-[var(--text-DEFAULT)]'"
                        class="flex h-6 flex-1 items-center justify-center gap-1 rounded text-[11px]
                               font-medium transition-colors"
                        :aria-pressed="preference === '{{ $value }}'">
                    <x-dynamic-component :component="$icon" class="size-3.5" />
                    <span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
    </div>

    <x-ui.dropdown-separator />

    @if ($me->is_admin)
        <x-ui.dropdown-item href="{{ url('/admin') }}" icon="icon.shield">
            {{ __('Administration') }}
        </x-ui.dropdown-item>
    @endif

    <x-ui.dropdown-item x-on:click="$dispatch('open-shortcuts-help')" shortcut="?">
        {{ __('Keyboard shortcuts') }}
    </x-ui.dropdown-item>

    <x-ui.dropdown-separator />

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <x-ui.dropdown-item type="submit" icon="icon.logout" class="[&>svg]:flip-rtl">{{ __('Sign out') }}</x-ui.dropdown-item>
    </form>
</x-ui.dropdown>
