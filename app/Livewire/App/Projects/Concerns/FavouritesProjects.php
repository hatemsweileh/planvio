<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects\Concerns;

use App\Models\Favorite;
use App\Models\Project;

/**
 * Reading and writing the star, with no opinion about where the star is drawn.
 *
 * A favourite is a per-user pin, not tenant data — `favorites` carries no `workspace_id`,
 * because the favourited record carries the tenancy. Nothing here authorizes: the caller
 * has the project in hand and is the one that knows whether the acting user may see it.
 */
trait FavouritesProjects
{
    protected function isFavourited(Project $project): bool
    {
        return Favorite::query()
            ->forUser($this->favouriteOwnerId())
            ->for($project)
            ->exists();
    }

    /**
     * Star or unstar, returning the state it landed in.
     *
     * New stars go to the top of the list rather than the bottom: the sidebar shows
     * favourites in `position` order, and the project somebody just starred is the one they
     * are about to open.
     */
    protected function setFavourite(Project $project): bool
    {
        $userId = $this->favouriteOwnerId();

        $existing = Favorite::query()
            ->forUser($userId)
            ->for($project)
            ->first();

        if ($existing !== null) {
            $existing->delete();

            return false;
        }

        $lowest = (int) Favorite::query()->forUser($userId)->min('position');

        // firstOrCreate rather than create: `unique(user_id, favoritable)` turns a
        // double-clicked star into a driver exception otherwise, and starring twice is not
        // an error worth showing anybody.
        Favorite::query()->firstOrCreate(
            [
                'user_id' => $userId,
                'favoritable_id' => $project->getKey(),
                'favoritable_type' => $project->getMorphClass(),
            ],
            ['position' => $lowest - 1],
        );

        return true;
    }

    private function favouriteOwnerId(): int
    {
        return (int) auth()->id();
    }
}
