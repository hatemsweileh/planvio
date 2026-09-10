<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\TaskStatus;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Task statuses are the columns of one project's board. Reading them follows `task.view`;
 * changing them is reshaping the project, so it follows `project.update` — which for a
 * workspace manager means managing that project (`+`).
 */
final class TaskStatusPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::TaskView,
        );
    }

    public function view(User $user, TaskStatus $status): bool
    {
        return $this->permits(
            $user,
            $status->workspace_id,
            Permission::TaskView,
            $this->projectIdOf($status),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::ProjectUpdate,
            $project,
        );
    }

    public function update(User $user, TaskStatus $status): bool
    {
        return $this->manages($user, $status);
    }

    public function delete(User $user, TaskStatus $status): bool
    {
        return $this->manages($user, $status);
    }

    public function reorder(User $user, TaskStatus $status): bool
    {
        return $this->manages($user, $status);
    }

    private function manages(User $user, TaskStatus $status): bool
    {
        return $this->permits(
            $user,
            $status->workspace_id,
            Permission::ProjectUpdate,
            $this->projectIdOf($status),
        );
    }
}
