<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A task now exists.
 *
 * Dispatched after the creating transaction commits, so a listener never sees a task that
 * was rolled back a moment later.
 */
final class TaskCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task $task,
        public readonly User $actor,
    ) {}
}
