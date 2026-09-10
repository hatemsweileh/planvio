<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A soft-deleted task came back, together with the descendants listed in $subtaskIds.
 */
final class TaskRestored implements ShouldDispatchAfterCommit
{
    /**
     * @param list<int> $subtaskIds
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $subtaskIds,
        public readonly User $actor,
    ) {}
}
