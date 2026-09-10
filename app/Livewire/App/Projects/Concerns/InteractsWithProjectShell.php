<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects\Concerns;

use App\Models\Project;
use App\Models\Workspace;
use Livewire\Attributes\Computed;

/**
 * The behaviour behind `<x-app.project-shell>`'s star.
 *
 * It is a trait rather than part of the Blade component because starring writes, and a Blade
 * component has nowhere to write from. Any project tab that wants an interactive star adopts
 * this and passes `:favourite="$this->isFavourite"`; a tab that does not simply omits the
 * prop and the shell renders no star, which is better than rendering a dead one.
 *
 * Adopting it requires a public `$project` and `$workspace`, which every project tab has —
 * they are its two route parameters.
 *
 * @property Project $project
 * @property Workspace $workspace
 */
trait InteractsWithProjectShell
{
    use FavouritesProjects;

    /**
     * Whether the acting user has starred the project this screen is showing.
     */
    #[Computed]
    public function isFavourite(): bool
    {
        return $this->isFavourited($this->project);
    }

    /**
     * The star is re-authorized on every toggle rather than trusted from the render that
     * drew it: a Livewire action is a public endpoint, and `view` is the cheapest possible
     * answer to "is this still your project".
     */
    public function toggleFavourite(): void
    {
        $this->authorize('view', $this->project);

        $starred = $this->setFavourite($this->project);

        unset($this->isFavourite);

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: $starred
                ? __('“:project” added to your favourites.', ['project' => $this->project->name])
                : __('“:project” removed from your favourites.', ['project' => $this->project->name]),
        );
    }
}
