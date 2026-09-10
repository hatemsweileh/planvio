<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskDeleted;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft-deletes a task and everything hanging under it.
 *
 * `tasks.parent_id` uses `nullOnDelete`, which only fires on a real DELETE — a soft delete
 * is an UPDATE, so nothing cascades on its own and an orphaned subtask would keep appearing
 * on the board with a parent nobody can open. The descendants are therefore walked and
 * stamped with the *same* `deleted_at` as the task, which is what lets
 * {@see RestoreTask} tell the cascade apart from subtasks that were deleted earlier for
 * their own reasons.
 *
 * Deleting an already-deleted task does nothing and records nothing.
 */
final class DeleteTask
{
    /** Ids per statement while walking and stamping the tree. */
    private const CHUNK = 200;

    /**
     * A guard against a cycle in `parent_id` that should be impossible — see
     * {@see ConvertToSubtask} — but which would otherwise loop here forever.
     */
    private const MAX_DEPTH = 50;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, User $actor): Task
    {
        if ($task->trashed()) {
            return $task;
        }

        $descendantIds = [];

        DB::transaction(function () use ($task, $actor, &$descendantIds): void {
            $descendantIds = $this->descendantIds((int) $task->getKey());

            $task->delete();

            $deletedAt = $task->deleted_at;

            foreach (array_chunk($descendantIds, self::CHUNK) as $chunk) {
                Task::query()
                    ->whereIn('id', $chunk)
                    ->update(['deleted_at' => $deletedAt, 'updated_at' => $deletedAt]);
            }

            $this->activity->record($task, 'deleted', $actor, [
                'title' => $task->title,
                'subtask_ids' => $descendantIds,
            ]);
        });

        event(new TaskDeleted($task, $descendantIds, $actor));

        return $task;
    }

    /**
     * Every live task below this one, breadth first.
     *
     * Iterative and level-by-level: one query per level rather than one per node, and no
     * recursion to blow the stack on a deep tree.
     *
     * @return list<int>
     */
    private function descendantIds(int $rootId): array
    {
        $found = [];
        $frontier = [$rootId];
        $depth = 0;

        while ($frontier !== [] && $depth++ < self::MAX_DEPTH) {
            $next = [];

            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                $children = Task::query()
                    ->whereIn('parent_id', $chunk)
                    ->pluck('id');

                foreach ($children as $id) {
                    $id = (int) $id;

                    if ($id !== $rootId && ! in_array($id, $found, true)) {
                        $found[] = $id;
                        $next[] = $id;
                    }
                }
            }

            $frontier = $next;
        }

        return $found;
    }
}
