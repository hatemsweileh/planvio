<?php

declare(strict_types=1);

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskAssigned as TaskAssignedEvent;
use App\Notifications\Tasks\TaskAssigned as TaskAssignedNotification;
use App\Services\NotificationDispatcher;

/**
 * Tells the new assignee they have been handed something.
 *
 * Runs inline rather than on the queue: it does one lookup and hands over to a notification
 * that is itself queued after commit, so the request pays for a dispatch and nothing more.
 * Pushing this onto the queue would only move the same work behind a worker that has no
 * workspace bound (ARCHITECTURE.md §3) and would have to re-establish one.
 *
 * Unassignment is silent. There is no phrasing of "that task is not yours any more" that
 * does not read as a reprimand, and the change is in the activity feed either way.
 */
final class NotifyTaskAssignee
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function handle(TaskAssignedEvent $event): void
    {
        $assignee = $event->assignee;

        if ($assignee === null) {
            return;
        }

        $task = $event->task->loadMissing(['project', 'workspace']);

        $this->notifications->send(
            recipients: $assignee,
            notification: new TaskAssignedNotification($task, $event->actor, $event->previous),
            category: 'task.assigned',
            actor: $event->actor,
            workspace: $task->workspace,
        );
    }
}
