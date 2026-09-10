<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * The activity feed is append-only. Nothing but the Actions that emit it may write here, so
 * every mutating method is a flat refusal rather than a permission check: a feed a user can
 * edit cannot be used to reconstruct what happened, and the AI layer's accountability rests
 * on it (ARCHITECTURE.md §7.1). Retention pruning is a scheduled job, not a user action.
 */
final class ActivityPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::ProjectView,
        );
    }

    /**
     * An entry carries its own `project_id`, so a guest sees only the projects they are in;
     * a workspace-level entry (null project) has nothing for the `*` cell to match and stays
     * out of their feed.
     */
    public function view(User $user, Activity $activity): bool
    {
        return $this->permits(
            $user,
            $activity->workspace_id,
            Permission::ProjectView,
            $this->projectIdOf($activity),
        );
    }

    /**
     * Feed rows are written by the Actions that emit them, never by a user, so this takes no
     * container argument at all — a caller that passes one gets the same refusal.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function restore(User $user, Activity $activity): bool
    {
        return false;
    }

    public function forceDelete(User $user, Activity $activity): bool
    {
        return false;
    }
}
