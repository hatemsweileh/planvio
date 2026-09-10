<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiPolicy;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * The allow/deny/approval rules the agent runs under. `workspace_id` is nullable: a null row
 * is the install-wide default that constrains every tenant.
 *
 * A workspace admin may read that default, because it explains why a tool of theirs is being
 * refused, but may not change it — one tenant must never be able to loosen the rules another
 * tenant runs under. Editing it is platform administration, reached from `/admin` with no
 * workspace bound.
 */
final class AiPolicyPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::AiManagePolicies,
        );
    }

    public function view(User $user, AiPolicy $policy): bool
    {
        if ($policy->workspace_id === null) {
            return $this->permitsSomewhere(
                $user,
                $this->currentWorkspace(),
                Permission::AiManagePolicies,
            );
        }

        return $this->permits($user, $policy->workspace_id, Permission::AiManagePolicies);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::AiManagePolicies,
        );
    }

    public function update(User $user, AiPolicy $policy): bool
    {
        return $this->manages($user, $policy);
    }

    public function delete(User $user, AiPolicy $policy): bool
    {
        return $this->manages($user, $policy);
    }

    public function toggle(User $user, AiPolicy $policy): bool
    {
        return $this->manages($user, $policy);
    }

    private function manages(User $user, AiPolicy $policy): bool
    {
        if ($policy->workspace_id === null) {
            return false;
        }

        return $this->permits($user, $policy->workspace_id, Permission::AiManagePolicies);
    }
}
