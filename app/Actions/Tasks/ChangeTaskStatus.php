<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskStatusChanged;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\Tasks\TaskStatusChanged as TaskStatusChangedNotification;
use App\Services\ActivityLogger;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moves a task into another board column and keeps the derived completion state honest.
 *
 * `completed_at` is the column every "is this still open?" query reads — `Task::scopeOpen`,
 * the overdue scope, the project progress rollup — so it is written here from the category
 * of the new status rather than left to the caller. A task sitting in a Done column with a
 * null `completed_at` would show up as overdue forever.
 *
 * Re-running with the status the task already has is a no-op: no write, no activity row, no
 * notification. Board clients retry drops, and a retry must not wake every watcher again.
 */
final class ChangeTaskStatus
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function __invoke(Task $task, TaskStatus $status, User $actor): Task
    {
        if ((int) $status->project_id !== (int) $task->project_id) {
            throw new TaskStatusNotInProject($status, (int) $task->project_id);
        }

        if ((int) $task->status_id === (int) $status->getKey()) {
            return $task;
        }

        $from = $task->relationLoaded('status') ? $task->getRelation('status') : $task->status()->first();
        $wasCompleted = $task->completed_at !== null;
        $isCompleted = $status->is_completed || $status->category->isClosed();

        DB::transaction(function () use ($task, $status, $actor, $from, $wasCompleted, $isCompleted): void {
            $task->status_id = $status->getKey();

            // Closing stamps the moment; reopening clears it. Cancelled counts as closed —
            // no further work is expected — but only Done says the work was finished, so that
            // is the only category that drives progress to 100.
            if ($isCompleted) {
                $task->completed_at = $wasCompleted ? $task->completed_at : Carbon::now();

                if ($status->category->isCompleted()) {
                    $task->progress = 100;
                }
            } else {
                $task->completed_at = null;
            }

            $task->save();

            $this->activity->record($task, 'status_changed', $actor, [
                'attribute' => 'status_id',
                'old' => $from === null ? null : (int) $from->getKey(),
                'new' => (int) $status->getKey(),
                'old_status' => $from?->name,
                'new_status' => $status->name,
                'completed' => $isCompleted,
            ]);
        });

        $task->setRelation('status', $status);

        event(new TaskStatusChanged($task, $from, $status, $actor, $wasCompleted, $isCompleted));

        $this->notifyWatchers($task, $from, $status, $actor);

        return $task;
    }

    /**
     * Everyone following the task, minus the person who moved it and anyone who has switched
     * this category off — {@see NotificationDispatcher} owns both rules so they are applied
     * the same way everywhere.
     *
     * Queued behind the commit so a rolled-back move cannot announce itself, and so the rows
     * are never written inside a transaction that may still fail.
     */
    private function notifyWatchers(Task $task, ?TaskStatus $from, TaskStatus $status, User $actor): void
    {
        DB::afterCommit(function () use ($task, $from, $status, $actor): void {
            $watchers = $task->watchers()->get();

            if ($watchers->isEmpty()) {
                return;
            }

            $this->notifications->send(
                recipients: $watchers,
                notification: new TaskStatusChangedNotification($task, $from, $status, $actor),
                category: 'task.status_changed',
                actor: $actor,
                workspace: $task->workspace,
            );
        });
    }
}
