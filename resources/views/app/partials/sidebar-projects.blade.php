@php
    /**
     * Favourites and recents are shared by the SetCurrentWorkspace middleware so this
     * partial never queries: the sidebar renders on every request and must not add two
     * queries to each one.
     */
    $favourites = $sidebarFavourites ?? collect();
    $recents = $sidebarRecents ?? collect();
@endphp

@if ($favourites->isNotEmpty())
    <div x-show="!collapsed" x-cloak class="space-y-0.5">
        <p class="px-2 pb-1 text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
            {{ __('Favourites') }}
        </p>
        @foreach ($favourites as $project)
            <x-app.nav-item :href="route('app.projects.show', [$workspace, $project])"
                            :color="$project->color"
                            :label="$project->name"
                            :active="request()->route('project')?->is($project) ?? false" />
        @endforeach
    </div>
@endif

@if ($recents->isNotEmpty())
    <div x-show="!collapsed" x-cloak class="space-y-0.5">
        <div class="flex items-center justify-between gap-1 px-2 pb-1">
            <p class="text-2xs font-semibold uppercase tracking-wider text-[var(--text-subtle)]">
                {{ __('Recent') }}
            </p>
            <a href="{{ route('app.projects.index', $workspace) }}"
               class="text-2xs text-[var(--text-subtle)] transition-colors hover:text-[var(--text-DEFAULT)]">
                {{ __('All') }}
            </a>
        </div>
        @foreach ($recents as $project)
            <x-app.nav-item :href="route('app.projects.show', [$workspace, $project])"
                            :color="$project->color"
                            :label="$project->name"
                            :active="request()->route('project')?->is($project) ?? false" />
        @endforeach
    </div>
@endif

@if ($favourites->isEmpty() && $recents->isEmpty())
    <div x-show="!collapsed" x-cloak class="px-2 pt-1">
        <p class="text-xs leading-relaxed text-[var(--text-subtle)]">
            {{ __('Projects you open or star will appear here.') }}
        </p>
    </div>
@endif
