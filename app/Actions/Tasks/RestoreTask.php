<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskRestored;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Brings a soft-deleted task back, along with the subtasks that went down with it.
 *
 * "Went down with it" is decided by the `deleted_at` stamp, not by the tree: a subtask that
 * somebody deleted on its own last week shares the same parent but not the same moment, and
 * restoring it here would quietly undo a separate decision. {@see DeleteTask} writes one
 * timestamp across the whole cascade precisely so this can be told apart.
 *
 * Restoring a task that is not deleted does nothing and records nothing.
 */
final class RestoreTask
{
    private const CHUNK = 200;

    private const MAX_DEPTH = 50;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, User $actor): Task
    {
        if (! $task->trashed()) {
            return $task;
        }

        $restoredIds = [];

        DB::transaction(function () use ($task, $actor, &$restoredIds): void {
            $deletedAt = $task->deleted_at;
            $restoredIds = $this->cascadedDescendantIds((int) $task->getKey(), $deletedAt);

            $task->restore();

            foreach (array_chunk($restoredIds, self::CHUNK) as $chunk) {
                Task::withTrashed()
                    ->whereIn('id', $chunk)
                    ->update(['deleted_at' => null]);
            }

            $this->activity->record($task, 'restored', $actor, [
                'title' => $task->title,
                'subtask_ids' => $restoredIds,
            ]);
        });

        event(new TaskRestored($task, $restoredIds, $actor));

        return $task;
    }

    /**
     * The descendants trashed in the same operation as the root.
     *
     * @return list<int>
     */
    private function cascadedDescendantIds(int $rootId, ?DateTimeInterface $deletedAt): array
    {
        $found = [];
        $frontier = [$rootId];
        $depth = 0;

        while ($frontier !== [] && $depth++ < self::MAX_DEPTH) {
            $next = [];

            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                $children = Task::onlyTrashed()
                    ->whereIn('parent_id', $chunk)
                    ->where('deleted_at', $deletedAt)
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
