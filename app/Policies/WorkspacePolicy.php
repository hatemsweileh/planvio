<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

final class WorkspacePolicy
{
    use ChecksWorkspaceAccess;

    /**
     * The workspace switcher. Every row is still authorized by {@see self::view()} and the
     * query is narrowed with `Workspace::forMember()`, so an active account may ask.
     */
    public function viewAny(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::WorkspaceView);
    }

    /**
     * Creating a workspace touches no existing tenant, so no membership can be required —
     * the creator becomes its owner. Only an active account may do it.
     */
    public function create(User $user): bool
    {
        return (bool) $user->is_active;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::WorkspaceManage);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::WorkspaceDelete);
    }

    public function restore(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::WorkspaceDelete);
    }

    /**
     * Irreversibly destroying a tenant and every row hanging off it is platform
     * administration, not product UX: it is reachable only through `/admin`, where no
     * workspace is bound and `Gate::before` lets a platform admin through.
     */
    public function forceDelete(User $user, Workspace $workspace): bool
    {
        return false;
    }

    public function manageSettings(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::SettingsManage);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::UsersManage);
    }

    public function invite(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::UsersManage);
    }

    public function viewReports(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::ReportsView);
    }

    public function viewAudit(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::WorkspaceManage);
    }

    public function manageBilling(User $user, Workspace $workspace): bool
    {
        return $this->permits($user, $workspace, Permission::BudgetManage);
    }

    /**
     * Handing the tenant to somebody else is the one act an admin must not perform on the
     * owner's behalf.
     */
    public function transferOwnership(User $user, Workspace $workspace): bool
    {
        return $this->workspaceRole($user, $workspace) === WorkspaceRole::Owner;
    }

    /**
     * The owner cannot walk out of their own workspace — ownership has to move first, or
     * the workspace has to be deleted.
     */
    public function leave(User $user, Workspace $workspace): bool
    {
        $role = $this->workspaceRole($user, $workspace);

        return $role !== null && $role !== WorkspaceRole::Owner;
    }
}
