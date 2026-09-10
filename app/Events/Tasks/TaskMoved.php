<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A task was repositioned on the board, within a column or across two of them.
 *
 * A cross-column drop also raises TaskStatusChanged; this event is about the ordering, so
 * it carries the positions rather than the statuses. Positions are passed as strings:
 * the column is decimal(20,10) and a float round-trip would quietly lose the low digits
 * that fractional ordering depends on.
 */
final class TaskMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task $task,
        public readonly int $fromStatusId,
        public readonly int $toStatusId,
        public readonly string $fromPosition,
        public readonly string $toPosition,
        public readonly User $actor,
    ) {}
}
