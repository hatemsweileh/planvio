<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Project statuses are a workspace-wide vocabulary reused across every project, so editing
 * one is a settings change, not a project change — a project manager renaming "In flight"
 * would rename it for the whole workspace.
 */
final class ProjectStatusPolicy
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

    public function view(User $user, ProjectStatus $status): bool
    {
        return $this->permits($user, $status->workspace_id, Permission::WorkspaceView);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::SettingsManage,
        );
    }

    public function update(User $user, ProjectStatus $status): bool
    {
        return $this->permits($user, $status->workspace_id, Permission::SettingsManage);
    }

    public function delete(User $user, ProjectStatus $status): bool
    {
        return $this->permits($user, $status->workspace_id, Permission::SettingsManage);
    }

    public function reorder(User $user, ProjectStatus $status): bool
    {
        return $this->permits($user, $status->workspace_id, Permission::SettingsManage);
    }
}
