<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use DomainException;

/**
 * `tasks.parent_id` is a tree, and a tree with a loop in it hangs every renderer that walks
 * it. Re-parenting a task under one of its own descendants would create exactly that.
 */
final class SubtaskCycleDetected extends DomainException
{
    /**
     * @param list<int> $path task ids from the proposed parent up to the task itself
     */
    public function __construct(
        public readonly Task $task,
        public readonly Task $parent,
        public readonly array $path,
    ) {
        parent::__construct(__('actions.tasks.subtask_cycle'));
    }
}
