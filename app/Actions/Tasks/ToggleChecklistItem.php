<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ticks or unticks a checklist item.
 *
 * `$done` is the state to end in rather than "flip it": a double-tapped checkbox or a
 * retried request then lands on the state the person asked for instead of undoing itself,
 * and re-sending the same state writes nothing at all.
 *
 * `completed_at` and `completed_by` are cleared on unticking — leaving them behind would
 * claim that somebody finished an item that is once again outstanding.
 */
final class ToggleChecklistItem
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, TaskChecklistItem $item, bool $done, User $actor): TaskChecklistItem
    {
        if ((int) $item->task_id !== (int) $task->getKey()) {
            throw new ChecklistItemNotOnTask($item, $task);
        }

        if ($item->is_done === $done) {
            return $item;
        }

        return DB::transaction(function () use ($task, $item, $done, $actor): TaskChecklistItem {
            $item->is_done = $done;
            $item->completed_at = $done ? Carbon::now() : null;
            $item->completed_by = $done ? $actor->getKey() : null;
            $item->save();

            $this->activity->record(
                $task,
                $done ? 'checklist_item_completed' : 'checklist_item_reopened',
                $actor,
                [
                    'item_id' => (int) $item->getKey(),
                    'title' => $item->title,
                ],
            );

            return $item;
        });
    }
}
