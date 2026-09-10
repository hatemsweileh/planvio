<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A dependency is an edge between two tasks, so it is authorized as a change to the
 * dependent task: reading follows `task.view`, wiring follows `task.update`.
 *
 * The row carries `workspace_id` but no `project_id`, so the project has to come from the
 * task. That lookup sits behind a closure and only runs when the acting role's cell is
 * conditional — a list of a task's dependencies costs an owner or admin nothing.
 */
final class TaskDependencyPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Task $task = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($task?->workspace_id),
            Permission::TaskView,
        );
    }

    public function view(User $user, TaskDependency $dependency): bool
    {
        return $this->permits(
            $user,
            $dependency->workspace_id,
            Permission::TaskView,
            fn (): ?int => $this->relatedProjectId($this->task($dependency), $dependency->workspace_id),
        );
    }

    public function create(User $user, ?Task $task = null): bool
    {
        if ($task === null) {
            return false;
        }

        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TaskUpdate,
            $this->projectIdOf($task),
            fn (): bool => $this->isOwnTask($user, $task),
        );
    }

    public function update(User $user, TaskDependency $dependency): bool
    {
        return $this->manages($user, $dependency);
    }

    public function delete(User $user, TaskDependency $dependency): bool
    {
        return $this->manages($user, $dependency);
    }

    private function manages(User $user, TaskDependency $dependency): bool
    {
        // Resolved once and shared by both refinements: the same task answers "which
        // project" and "is it theirs", and only one of the two is ever asked for.
        $task = null;
        $resolve = function () use ($dependency, &$task): ?Task {
            return $task ??= $this->task($dependency);
        };

        return $this->permits(
            $user,
            $dependency->workspace_id,
            Permission::TaskUpdate,
            fn (): ?int => $this->relatedProjectId($resolve(), $dependency->workspace_id),
            fn (): bool => ($resolved = $resolve()) !== null && $this->isOwnTask($user, $resolved),
        );
    }

    /**
     * The dependent task, outside the tenant scope — the scope is inert in jobs and the
     * workspace comparison in relatedProjectId() is what keeps the answer honest.
     */
    private function task(TaskDependency $dependency): ?Task
    {
        if ($dependency->relationLoaded('task')) {
            return $dependency->task;
        }

        $taskId = $dependency->task_id;

        return $taskId === null ? null : Task::withoutWorkspaceScope()->find($taskId);
    }

    private function isOwnTask(User $user, Task $task): bool
    {
        $userId = (int) $user->getKey();

        return (int) $task->assignee_id === $userId || (int) $task->reporter_id === $userId;
    }
}
