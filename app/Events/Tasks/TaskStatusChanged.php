<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A task moved from one board column to another.
 *
 * The two completion flags are carried explicitly because whether the move closed or
 * reopened the task is the question most listeners actually ask, and deriving it from the
 * statuses would mean repeating the same rule in each of them.
 */
final class TaskStatusChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task $task,
        public readonly ?TaskStatus $from,
        public readonly TaskStatus $to,
        public readonly User $actor,
        public readonly bool $wasCompleted,
        public readonly bool $isCompleted,
    ) {}
}
