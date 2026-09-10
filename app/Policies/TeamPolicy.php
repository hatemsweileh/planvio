<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Teams are a people-directory concept, so they follow `users.manage` for writes and plain
 * workspace membership for reads.
 */
final class TeamPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::WorkspaceView,
        );
    }

    public function view(User $user, Team $team): bool
    {
        return $this->permits($user, $team->workspace_id, Permission::WorkspaceView);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::UsersManage,
        );
    }

    public function update(User $user, Team $team): bool
    {
        return $this->permits($user, $team->workspace_id, Permission::UsersManage);
    }

    public function delete(User $user, Team $team): bool
    {
        return $this->permits($user, $team->workspace_id, Permission::UsersManage);
    }

    public function manageMembers(User $user, Team $team): bool
    {
        return $this->permits($user, $team->workspace_id, Permission::UsersManage);
    }
}
