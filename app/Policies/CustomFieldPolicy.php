<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CustomField;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A custom field with a null `project_id` is workspace-wide schema and belongs to
 * `settings.manage`. One scoped to a project is that project's own shape, so a project
 * manager may change it under `project.update` (`+`).
 */
final class CustomFieldPolicy
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

    public function view(User $user, CustomField $field): bool
    {
        return $this->permits(
            $user,
            $field->workspace_id,
            Permission::ProjectView,
            $this->projectIdOf($field),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        $workspace = $this->contextWorkspace($project);

        if ($this->permits($user, $workspace, Permission::SettingsManage)) {
            return true;
        }

        return $project !== null
            && $this->permits($user, $workspace, Permission::ProjectUpdate, $project);
    }

    public function update(User $user, CustomField $field): bool
    {
        return $this->manages($user, $field);
    }

    public function delete(User $user, CustomField $field): bool
    {
        return $this->manages($user, $field);
    }

    public function reorder(User $user, CustomField $field): bool
    {
        return $this->manages($user, $field);
    }

    private function manages(User $user, CustomField $field): bool
    {
        if ($this->permits($user, $field->workspace_id, Permission::SettingsManage)) {
            return true;
        }

        $projectId = $this->projectIdOf($field);

        return $projectId !== null
            && $this->permits($user, $field->workspace_id, Permission::ProjectUpdate, $projectId);
    }
}
