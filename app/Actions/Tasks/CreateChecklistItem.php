<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Adds one tick box to a task.
 *
 * Checklist items carry no `workspace_id` of their own — they are reachable only through
 * their task — so every activity row about them is recorded against the task. That is also
 * where anyone reading the feed expects to find it.
 */
final class CreateChecklistItem
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, string $title, User $actor, ?int $position = null): TaskChecklistItem
    {
        return DB::transaction(function () use ($task, $title, $actor, $position): TaskChecklistItem {
            $item = TaskChecklistItem::query()->create([
                'task_id' => $task->getKey(),
                'title' => $title,
                'is_done' => false,
                'position' => $position ?? $this->nextPosition($task),
            ]);

            $this->activity->record($task, 'checklist_item_added', $actor, [
                'item_id' => (int) $item->getKey(),
                'title' => $item->title,
            ]);

            return $item;
        });
    }

    private function nextPosition(Task $task): int
    {
        return (int) TaskChecklistItem::query()->forTask($task)->max('position') + 1;
    }
}
