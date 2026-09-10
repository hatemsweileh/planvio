<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskUpdated;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Re-parents a task: hangs it under another task, or promotes it back to the top level when
 * `$parent` is null.
 *
 * The invariant worth the code is the loop check. `tasks.parent_id` is a tree that the
 * board, the detail page and the delete cascade all walk, and a task made a child of one of
 * its own descendants closes that walk into a ring — every one of those walks would run
 * until it ran out of memory. The check climbs from the proposed parent towards the root,
 * iteratively and with a hard step budget, and refuses the move if it arrives back at the
 * task being moved.
 *
 * Re-parenting to the parent a task already has is a no-op.
 */
final class ConvertToSubtask
{
    /**
     * A tree cannot legitimately be this deep, and a graph that is has a loop the guard
     * below is there to survive rather than to explain.
     */
    private const MAX_DEPTH = 100;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, ?Task $parent, User $actor): Task
    {
        $previousId = $task->parent_id === null ? null : (int) $task->parent_id;
        $nextId = $parent === null ? null : (int) $parent->getKey();

        if ($previousId === $nextId) {
            return $task;
        }

        if ($parent !== null) {
            $this->assertUsableParent($task, $parent);
        }

        DB::transaction(function () use ($task, $actor, $previousId, $nextId): void {
            $task->parent_id = $nextId;
            $task->save();

            $this->activity->record(
                $task,
                $nextId === null ? 'promoted_to_task' : 'converted_to_subtask',
                $actor,
                [
                    'attribute' => 'parent_id',
                    'old' => $previousId,
                    'new' => $nextId,
                ],
            );
        });

        event(new TaskUpdated(
            $task,
            ['parent_id' => ['old' => $previousId, 'new' => $nextId]],
            $actor,
        ));

        return $task;
    }

    private function assertUsableParent(Task $task, Task $parent): void
    {
        $taskId = (int) $task->getKey();

        if ((int) $parent->getKey() === $taskId) {
            throw new SubtaskCycleDetected($task, $parent, [$taskId]);
        }

        if ((int) $parent->project_id !== (int) $task->project_id) {
            throw new ParentTaskNotInProject($parent, (int) $task->project_id);
        }

        // Climb from the proposed parent to the root. If the task being moved is anywhere on
        // that path it is an ancestor of its own would-be parent, and the link would close a
        // ring. The step budget means a graph that is already corrupt cannot hang the
        // request, it just refuses the edit.
        $path = [(int) $parent->getKey()];
        $cursor = $parent->parent_id === null ? null : (int) $parent->parent_id;
        $steps = 0;

        while ($cursor !== null && $steps++ < self::MAX_DEPTH) {
            $path[] = $cursor;

            if ($cursor === $taskId) {
                throw new SubtaskCycleDetected($task, $parent, $path);
            }

            $next = Task::withTrashed()->whereKey($cursor)->value('parent_id');
            $cursor = $next === null ? null : (int) $next;
        }

        if ($steps >= self::MAX_DEPTH) {
            throw new SubtaskCycleDetected($task, $parent, $path);
        }
    }
}
