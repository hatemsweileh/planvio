<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user's subscription to a task.
 *
 * Unwatching a task nobody was watching is silent: no row to delete, nothing worth telling
 * the feed about.
 */
final class UnwatchTask
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, User $watcher, User $actor): Task
    {
        DB::transaction(function () use ($task, $watcher, $actor): void {
            $removed = TaskWatcher::query()
                ->forTask($task)
                ->forUser($watcher)
                ->delete();

            if ($removed === 0) {
                return;
            }

            $this->activity->record($task, 'unwatched', $actor, [
                'user_id' => (int) $watcher->getKey(),
                'user' => $watcher->name,
            ]);
        });

        $task->unsetRelation('watchers');

        return $task;
    }
}
