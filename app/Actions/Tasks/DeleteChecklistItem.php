<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a checklist item for good — `task_checklist_items` has no soft deletes, because a
 * tick box nobody wants any more is not history worth keeping.
 *
 * The activity row is written before the delete, so the feed still names what was removed.
 * The returned model is the in-memory copy of the row that no longer exists, which is what
 * lets a caller undo or report on it.
 */
final class DeleteChecklistItem
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, TaskChecklistItem $item, User $actor): TaskChecklistItem
    {
        if ((int) $item->task_id !== (int) $task->getKey()) {
            throw new ChecklistItemNotOnTask($item, $task);
        }

        if (! $item->exists) {
            return $item;
        }

        return DB::transaction(function () use ($task, $item, $actor): TaskChecklistItem {
            $this->activity->record($task, 'checklist_item_removed', $actor, [
                'item_id' => (int) $item->getKey(),
                'title' => $item->title,
                'was_done' => $item->is_done,
            ]);

            $item->delete();

            return $item;
        });
    }
}
