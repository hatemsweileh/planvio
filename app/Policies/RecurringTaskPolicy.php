<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A recurrence is a standing instruction that keeps writing tasks long after its author has
 * moved on, so creating one is project configuration (`project.update`, `+` for a workspace
 * manager) rather than the `task.create` any member holds. Reading follows `task.view`.
 */
final class RecurringTaskPolicy
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

    public function view(User $user, RecurringTask $recurringTask): bool
    {
        return $this->permits(
            $user,
            $recurringTask->workspace_id,
            Permission::TaskView,
            $this->projectIdOf($recurringTask),
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

    public function update(User $user, RecurringTask $recurringTask): bool
    {
        return $this->manages($user, $recurringTask);
    }

    public function delete(User $user, RecurringTask $recurringTask): bool
    {
        return $this->manages($user, $recurringTask);
    }

    public function toggle(User $user, RecurringTask $recurringTask): bool
    {
        return $this->manages($user, $recurringTask);
    }

    /**
     * Generating the next occurrence by hand writes a task, so it needs the recurrence to be
     * manageable, not merely readable.
     */
    public function runNow(User $user, RecurringTask $recurringTask): bool
    {
        return $this->manages($user, $recurringTask);
    }

    private function manages(User $user, RecurringTask $recurringTask): bool
    {
        return $this->permits(
            $user,
            $recurringTask->workspace_id,
            Permission::ProjectUpdate,
            $this->projectIdOf($recurringTask),
        );
    }
}
