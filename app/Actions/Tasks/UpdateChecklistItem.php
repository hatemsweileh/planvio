<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Renames a checklist item. Ticking it is {@see ToggleChecklistItem}, which has its own
 * bookkeeping.
 *
 * Rewriting an item with the title it already has changes nothing.
 */
final class UpdateChecklistItem
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, TaskChecklistItem $item, string $title, User $actor): TaskChecklistItem
    {
        if ((int) $item->task_id !== (int) $task->getKey()) {
            throw new ChecklistItemNotOnTask($item, $task);
        }

        if ($item->title === $title) {
            return $item;
        }

        return DB::transaction(function () use ($task, $item, $title, $actor): TaskChecklistItem {
            $previous = $item->title;

            $item->title = $title;
            $item->save();

            $this->activity->record($task, 'checklist_item_updated', $actor, [
                'item_id' => (int) $item->getKey(),
                'attribute' => 'title',
                'old' => $previous,
                'new' => $title,
            ]);

            return $item;
        });
    }
}
