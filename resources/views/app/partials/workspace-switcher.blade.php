@php
    /**
     * Shared by the SetCurrentWorkspace middleware, alongside the favourites and recents,
     * so the shell costs three queries rather than four. The fallback is for the rare
     * render outside a bound workspace — an error page, a preview — not the normal path.
     */
    $switcherWorkspaces = $sidebarWorkspaces ?? auth()->user()?->workspaces()->orderBy('name')->get() ?? collect();
@endphp

<x-ui.dropdown align="start" width="w-64" class="min-w-0 flex-1">
    <x-slot:trigger>
        <button type="button"
                class="flex h-9 w-full items-center gap-2 rounded-md px-1.5 text-start transition-colors
                       hover:bg-[var(--surface-hover)]"
                aria-label="{{ __('Switch workspace') }}">

            @if ($workspace?->logo_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($workspace->logo_path) }}"
                     alt="" class="size-6 shrink-0 rounded object-cover">
            @else
                {{-- Falls back to the workspace initial on its own accent, so every
                     workspace is distinguishable at a glance in the collapsed rail. --}}
                <span class="grid size-6 shrink-0 place-items-center rounded text-[11px] font-bold text-white"
                      style="background-color: {{ $workspace?->accent_color ?? '#3F66B0' }}">
                    {{ mb_strtoupper(mb_substr($workspace?->name ?? 'P', 0, 1)) }}
                </span>
            @endif

            <span x-show="!collapsed" x-cloak class="min-w-0 flex-1">
                <span dir="auto" class="block truncate text-sm font-semibold text-[var(--text-strong)]">
                    {{ $workspace?->name ?? app(\App\Support\Branding::class)->name() }}
                </span>
            </span>

            <x-icon.chevron-down x-show="!collapsed" x-cloak
                                 class="size-3.5 shrink-0 text-[var(--text-subtle)]" />
        </button>
    </x-slot:trigger>

    <div class="px-2 py-1.5">
        <p class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Workspaces') }}
        </p>
    </div>

    @foreach ($switcherWorkspaces as $option)
        <x-ui.dropdown-item :href="route('app.home', $option)" :active="$option->is($workspace)">
            <span class="flex items-center gap-2">
                <span class="grid size-4 shrink-0 place-items-center rounded text-[9px] font-bold text-white"
                      style="background-color: {{ $option->accent_color }}">
                    {{ mb_strtoupper(mb_substr($option->name, 0, 1)) }}
                </span>
                <span dir="auto" class="truncate">{{ $option->name }}</span>
                @if ($option->is($workspace))
                    <svg class="ms-auto size-3.5 shrink-0 text-[var(--accent)]" viewBox="0 0 20 20"
                         fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round">
                        <path d="m4 10.5 4 4 8-9"/>
                    </svg>
                @endif
            </span>
        </x-ui.dropdown-item>
    @endforeach

    <x-ui.dropdown-separator />

    <x-ui.dropdown-item :href="route('workspaces.create')" icon="icon.plus">
        {{ __('New workspace') }}
    </x-ui.dropdown-item>

    @if ($workspace)
        @can('workspace.manage', $workspace)
            <x-ui.dropdown-item :href="route('app.settings', $workspace)" icon="icon.cog">
                {{ __('Workspace settings') }}
            </x-ui.dropdown-item>
        @endcan
    @endif
</x-ui.dropdown>
