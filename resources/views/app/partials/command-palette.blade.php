@php
    /**
     * The palette opens on the projects the sidebar already resolved, so Ctrl+K costs no
     * round trip. Both lists come from SetCurrentWorkspace, which fetched them in the same
     * query that drew the sidebar — mapping them here is free.
     */
    $paletteRecent = collect()
        ->concat(($sidebarFavourites ?? collect())->map(fn ($project) => [
            'id' => (int) $project->getKey(),
            'name' => (string) $project->name,
            'slug' => (string) $project->slug,
            'color' => $project->color,
            'starred' => true,
        ]))
        ->concat(($sidebarRecents ?? collect())->map(fn ($project) => [
            'id' => (int) $project->getKey(),
            'name' => (string) $project->name,
            'slug' => (string) $project->slug,
            'color' => $project->color,
            'starred' => false,
        ]))
        ->values()
        ->all();
@endphp

@livewire('app.shared.command-palette', ['recent' => $paletteRecent])
