<?php

declare(strict_types=1);

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskCompleted as TaskCompletedEvent;
use App\Models\Task;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Notifications\Tasks\TaskCompleted as TaskCompletedNotification;
use App\Notifications\Tasks\TaskStatusChanged;
use App\Services\NotificationDispatcher;

/**
 * Tells the people who asked for the work that it is finished.
 *
 * The interesting part is who is *left out*. Watchers already received
 * {@see TaskStatusChanged} for the very same click, so telling them
 * again under a second category would be two notifications for one event — the pattern that
 * teaches people to mute a product. Watcher ids are subtracted here rather than inside the
 * dispatcher because only this listener knows that the other notification went out.
 *
 * What remains is the people with a stake in the outcome rather than the movement: whoever
 * reported it, whoever created it, and the owner of the milestone it counts towards.
 */
final class NotifyTaskCompleted
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function handle(TaskCompletedEvent $event): void
    {
        $task = $event->task->loadMissing(['project', 'workspace', 'reporter', 'creator', 'milestone.owner']);

        $recipients = $this->stakeholders($task);

        if ($recipients === []) {
            return;
        }

        $this->notifications->send(
            recipients: $recipients,
            notification: new TaskCompletedNotification($task, $event->actor),
            category: 'task.completed',
            actor: $event->actor,
            workspace: $task->workspace,
        );
    }

    /**
     * Reporter, creator and milestone owner, minus everyone already told as a watcher.
     *
     * @return list<User>
     */
    private function stakeholders(Task $task): array
    {
        /** @var array<int, User> $candidates */
        $candidates = [];

        foreach ([$task->reporter, $task->creator, $task->milestone?->owner] as $user) {
            if ($user instanceof User) {
                $candidates[(int) $user->getKey()] = $user;
            }
        }

        if ($candidates === []) {
            return [];
        }

        $watchers = TaskWatcher::query()
            ->where('task_id', $task->getKey())
            ->whereIn('user_id', array_keys($candidates))
            ->pluck('user_id');

        foreach ($watchers as $userId) {
            unset($candidates[(int) $userId]);
        }

        return array_values($candidates);
    }
}
