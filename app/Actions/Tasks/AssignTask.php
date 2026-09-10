<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskAssigned;
use App\Models\Task;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hands a task to someone, or takes it back off them when `$assignee` is null.
 *
 * Assigning the person who already holds the task changes nothing and records nothing —
 * a bulk edit that touches a hundred tasks should not leave a hundred meaningless entries
 * in the feed for the ninety that were already right.
 */
final class AssignTask
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, ?User $assignee, User $actor): Task
    {
        // A domain invariant, not authorization: the workspace decides who can hold work.
        if ($assignee !== null && ! $assignee->memberOf($task->workspace)) {
            throw new AssigneeNotInWorkspace((int) $task->workspace_id, $assignee);
        }

        $previousId = $task->assignee_id === null ? null : (int) $task->assignee_id;
        $nextId = $assignee === null ? null : (int) $assignee->getKey();

        if ($previousId === $nextId) {
            return $task;
        }

        $previous = $previousId === null ? null : $task->assignee()->first();

        DB::transaction(function () use ($task, $assignee, $actor, $previousId, $nextId): void {
            $task->assignee_id = $nextId;
            $task->save();

            // Being handed a task is a reason to follow it. The row is idempotent, so an
            // assignee who already watches keeps their existing subscription date.
            if ($assignee !== null) {
                TaskWatcher::query()->firstOrCreate([
                    'task_id' => $task->getKey(),
                    'user_id' => $assignee->getKey(),
                ]);
            }

            $this->activity->record(
                $task,
                $nextId === null ? 'unassigned' : 'assigned',
                $actor,
                [
                    'attribute' => 'assignee_id',
                    'old' => $previousId,
                    'new' => $nextId,
                    'assignee' => $assignee?->name,
                ],
            );
        });

        if ($assignee !== null) {
            $task->setRelation('assignee', $assignee);
        } else {
            $task->unsetRelation('assignee');
        }

        event(new TaskAssigned($task, $previous, $assignee, $actor));

        return $task;
    }
}
