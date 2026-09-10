<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Policies\Concerns\ChecksWorkspaceAccess;
use App\Support\Permissions;

final class WorkspaceMemberPolicy
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

    /**
     * Seeing who else is in the workspace is part of being in it — the directory is what
     * makes assignment and mentions possible, and it exposes nothing a guest cannot
     * already see on a task.
     */
    public function view(User $user, WorkspaceMember $member): bool
    {
        return $this->permits($user, $member->workspace_id, Permission::WorkspaceView);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::UsersManage,
        );
    }

    public function update(User $user, WorkspaceMember $member): bool
    {
        return $this->manages($user, $member);
    }

    /**
     * Granting or revoking the owner role is the owner's alone: an admin who could promote
     * themselves to owner would make the owner role decorative.
     */
    public function assignRole(User $user, WorkspaceMember $member, WorkspaceRole $role): bool
    {
        if (! $this->manages($user, $member)) {
            return false;
        }

        if ($role !== WorkspaceRole::Owner) {
            return true;
        }

        return $this->workspaceRole($user, $member->workspace_id) === WorkspaceRole::Owner;
    }

    public function delete(User $user, WorkspaceMember $member): bool
    {
        return $this->manages($user, $member);
    }

    /**
     * Shared guard for every write against a membership row.
     *
     * Beyond the matrix it holds two invariants the matrix cannot express: nobody edits
     * their own membership through this path (that is `WorkspacePolicy::leave`, and it
     * stops an admin from locking the workspace by demoting themselves), and only an owner
     * may touch another owner.
     */
    private function manages(User $user, WorkspaceMember $member): bool
    {
        $role = $this->workspaceRole($user, $member->workspace_id);

        if ($role === null) {
            return false;
        }

        if (! Permissions::has($role, Permission::UsersManage)) {
            return false;
        }

        if ((int) $member->user_id === (int) $user->getKey()) {
            return false;
        }

        if ($member->role === WorkspaceRole::Owner && $role !== WorkspaceRole::Owner) {
            return false;
        }

        return true;
    }
}
