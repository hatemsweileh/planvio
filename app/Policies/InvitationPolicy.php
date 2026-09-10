<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Two routes lead to an invitation: workspace administration (`users.manage`) and a project
 * manager adding somebody to their own project (`project.manage_members`, the `+` cell).
 * Accepting an invitation is deliberately absent — that flow is token-authenticated and runs
 * before the invitee is a member of anything.
 */
final class InvitationPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        $workspace = $this->contextWorkspace($context);

        if ($this->permitsSomewhere($user, $workspace, Permission::UsersManage)) {
            return true;
        }

        return $this->permitsSomewhere($user, $workspace, Permission::ProjectManageMembers);
    }

    public function view(User $user, Invitation $invitation): bool
    {
        return $this->manages($user, $invitation);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        $workspace = $this->contextWorkspace($context);

        if ($this->permitsSomewhere($user, $workspace, Permission::UsersManage)) {
            return true;
        }

        // A project manager may only invite into a project, so the check needs one.
        return $context instanceof Project
            && $this->permits($user, $workspace, Permission::ProjectManageMembers, $context);
    }

    public function update(User $user, Invitation $invitation): bool
    {
        return $this->manages($user, $invitation);
    }

    public function delete(User $user, Invitation $invitation): bool
    {
        return $this->manages($user, $invitation);
    }

    public function resend(User $user, Invitation $invitation): bool
    {
        return $this->manages($user, $invitation);
    }

    public function revoke(User $user, Invitation $invitation): bool
    {
        return $this->manages($user, $invitation);
    }

    private function manages(User $user, Invitation $invitation): bool
    {
        if ($this->permits($user, $invitation->workspace_id, Permission::UsersManage)) {
            return true;
        }

        return $this->permits(
            $user,
            $invitation->workspace_id,
            Permission::ProjectManageMembers,
            $this->projectIdOf($invitation),
        );
    }
}
