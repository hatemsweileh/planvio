<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Subscribes a user to a task's notifications.
 *
 * Watching a task twice leaves one row and writes one activity entry: `task_watchers` is
 * unique on (task_id, user_id), and a second click must not read as a second event.
 */
final class WatchTask
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Task $task, User $watcher, User $actor): Task
    {
        // Watchers are sent notifications about the task, so someone outside the workspace
        // must never become one.
        if (! $watcher->memberOf($task->workspace)) {
            throw new WatcherNotInWorkspace($task, $watcher);
        }

        DB::transaction(function () use ($task, $watcher, $actor): void {
            $subscription = TaskWatcher::query()->firstOrCreate([
                'task_id' => $task->getKey(),
                'user_id' => $watcher->getKey(),
            ]);

            if (! $subscription->wasRecentlyCreated) {
                return;
            }

            $this->activity->record($task, 'watched', $actor, [
                'user_id' => (int) $watcher->getKey(),
                'user' => $watcher->name,
            ]);
        });

        $task->unsetRelation('watchers');

        return $task;
    }
}
