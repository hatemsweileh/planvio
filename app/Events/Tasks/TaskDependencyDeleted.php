<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A dependency edge was removed. The row is gone by the time listeners run, so the two
 * endpoints are carried instead of the model.
 */
final class TaskDependencyDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task $task,
        public readonly int $dependsOnTaskId,
        public readonly User $actor,
    ) {}
}
