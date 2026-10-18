<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskStatusChanged;
use App\Models\Activity;
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
        $leavingDone = $from instanceof TaskStatus && $from->category->isCompleted() && ! $status->category->isCompleted();

        DB::transaction(function () use ($task, $status, $actor, $from, $wasCompleted, $isCompleted, $leavingDone): void {
            $task->status_id = $status->getKey();

            // Closing stamps the moment; reopening clears it. Cancelled counts as closed —
            // no further work is expected — but only Done says the work was finished, so that
            // is the only category that drives progress to 100.
            //
            // The 100 is not left behind when the task is reopened. Nothing in the product
            // edits progress by hand, so a reopened task stuck at 100 would claim to be
            // finished. A checklist is the work itself, so it wins: progress becomes the
            // share of its items that are ticked. With no checklist, the progress from
            // before the task was closed is put back. That earlier value is recorded on
            // the activity row written when progress was forced to 100.
            $previousProgress = null;

            if ($isCompleted) {
                $task->completed_at = $wasCompleted ? $task->completed_at : Carbon::now();

                if ($status->category->isCompleted() && (int) $task->progress !== 100) {
                    $previousProgress = (int) $task->progress;
                    $task->progress = 100;
                }
            } else {
                $task->completed_at = null;

                if ($leavingDone) {
                    $task->progress = $this->progressAfterReopening($task);
                }
            }

            $task->save();

            $properties = [
                'attribute' => 'status_id',
                'old' => $from === null ? null : (int) $from->getKey(),
                'new' => (int) $status->getKey(),
                'old_status' => $from?->name,
                'new_status' => $status->name,
                'completed' => $isCompleted,
            ];

            if ($previousProgress !== null) {
                $properties['previous_progress'] = $previousProgress;
            }

            $this->activity->record($task, 'status_changed', $actor, $properties);
        });

        $task->setRelation('status', $status);

        event(new TaskStatusChanged($task, $from, $status, $actor, $wasCompleted, $isCompleted));

        $this->notifyWatchers($task, $from, $status, $actor);

        return $task;
    }

    /**
     * Progress for a task that has just left a Done column.
     *
     * Checklist items are counted rather than trusted from a cached percentage, because
     * the ticks are the work. Without any, the value saved when the task was closed is
     * restored; a task that was created already done has nothing to restore and starts
     * again at zero.
     */
    private function progressAfterReopening(Task $task): int
    {
        $total = $task->checklistItems()->count();

        if ($total > 0) {
            $done = $task->checklistItems()->where('is_done', true)->count();

            return (int) round(($done / $total) * 100);
        }

        $activities = Activity::query()
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->getKey())
            ->where('event', 'status_changed')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($activities as $activity) {
            $properties = $activity->properties;

            if (! is_array($properties) || ! array_key_exists('previous_progress', $properties)) {
                continue;
            }

            return max(0, min(100, (int) $properties['previous_progress']));
        }

        return 0;
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
