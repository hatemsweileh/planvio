<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

final class TaskPolicy
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

    public function view(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TaskView,
            $this->projectIdOf($task),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::TaskCreate,
            $project,
        );
    }

    /**
     * The `~` cell: a plain member may edit only the tasks they are the assignee or the
     * reporter of. Ownership is passed as a closure so an owner, admin or manager — whose
     * cell is `Y` — never evaluates it.
     */
    public function update(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TaskUpdate,
            $this->projectIdOf($task),
            fn (): bool => $this->isOwnTask($user, $task),
        );
    }

    public function delete(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TaskDelete,
            $this->projectIdOf($task),
        );
    }

    public function restore(User $user, Task $task): bool
    {
        return $this->delete($user, $task);
    }

    public function forceDelete(User $user, Task $task): bool
    {
        return $this->delete($user, $task);
    }

    public function assign(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TaskAssign,
            $this->projectIdOf($task),
        );
    }

    public function changeStatus(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function move(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    public function comment(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TaskComment,
            $this->projectIdOf($task),
        );
    }

    public function attach(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::AttachmentUpload,
            $this->projectIdOf($task),
        );
    }

    public function logTime(User $user, Task $task): bool
    {
        return $this->permits(
            $user,
            $task->workspace_id,
            Permission::TimeLog,
            $this->projectIdOf($task),
        );
    }

    /**
     * Watching is a personal notification preference, so it needs no more than the right to
     * see the task.
     */
    public function watch(User $user, Task $task): bool
    {
        return $this->view($user, $task);
    }

    private function isOwnTask(User $user, Task $task): bool
    {
        $userId = (int) $user->getKey();

        return (int) $task->assignee_id === $userId || (int) $task->reporter_id === $userId;
    }
}
