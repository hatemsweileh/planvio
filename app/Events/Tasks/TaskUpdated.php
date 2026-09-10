<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * One or more plain attributes of a task changed.
 *
 * Status and assignee raise their own events; this one carries everything else.
 */
final class TaskUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes keyed by column name
     */
    public function __construct(
        public readonly Task $task,
        public readonly array $changes,
        public readonly User $actor,
    ) {}
}
