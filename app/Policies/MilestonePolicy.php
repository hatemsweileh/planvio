<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

final class MilestonePolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::MilestoneView,
        );
    }

    public function view(User $user, Milestone $milestone): bool
    {
        return $this->permits(
            $user,
            $milestone->workspace_id,
            Permission::MilestoneView,
            $this->projectIdOf($milestone),
        );
    }

    /**
     * A milestone only exists inside a project, so the `+` cell always has something to
     * refine against — a null project denies rather than granting workspace-wide.
     */
    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::MilestoneManage,
            $project,
        );
    }

    public function update(User $user, Milestone $milestone): bool
    {
        return $this->manages($user, $milestone);
    }

    public function delete(User $user, Milestone $milestone): bool
    {
        return $this->manages($user, $milestone);
    }

    public function restore(User $user, Milestone $milestone): bool
    {
        return $this->manages($user, $milestone);
    }

    public function forceDelete(User $user, Milestone $milestone): bool
    {
        return $this->manages($user, $milestone);
    }

    public function complete(User $user, Milestone $milestone): bool
    {
        return $this->manages($user, $milestone);
    }

    private function manages(User $user, Milestone $milestone): bool
    {
        return $this->permits(
            $user,
            $milestone->workspace_id,
            Permission::MilestoneManage,
            $this->projectIdOf($milestone),
        );
    }
}
