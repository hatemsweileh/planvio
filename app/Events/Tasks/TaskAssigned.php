<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The assignee of a task changed. A null assignee means the task was unassigned.
 */
final class TaskAssigned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task $task,
        public readonly ?User $previous,
        public readonly ?User $assignee,
        public readonly User $actor,
    ) {}
}
