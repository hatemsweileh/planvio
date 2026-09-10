<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Listeners\Tasks\RaiseTaskCompleted;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A task crossed from open to closed.
 *
 * Distinct from {@see TaskStatusChanged}, which fires on every column move including the
 * ones between two open columns and the ones that reopen a closed task. Completion is the
 * event integrations, reports and reminders actually care about, and deriving it from a
 * status change means every one of them re-implements the same "was it closed before?"
 * comparison — with one of them eventually getting it wrong.
 *
 * Raised once per transition by {@see RaiseTaskCompleted}, so
 * re-saving an already-closed task is silent.
 */
final class TaskCompleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Task $task,
        public readonly TaskStatus $status,
        public readonly ?User $actor,
    ) {}
}
