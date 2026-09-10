<?php

declare(strict_types=1);

namespace App\Listeners\Tasks;

use App\Events\Tasks\TaskCompleted;
use App\Events\Tasks\TaskStatusChanged;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;

/**
 * Turns the one column move that closes a task into {@see TaskCompleted}.
 *
 * The status-change event already carries `wasCompleted` and `isCompleted`, so the test is a
 * comparison rather than a query. Doing it here, once, is the point: otherwise every
 * listener, webhook and report that cares about completion repeats the same two-flag check,
 * and the one that gets it backwards fires on reopening instead.
 *
 * The action that owns the transition is not touched — this sits downstream of it, which
 * also means an AI tool or an import that moves a task through the same action gets the
 * completion event for free.
 */
final class RaiseTaskCompleted
{
    public function __construct(private readonly EventDispatcher $events) {}

    public function handle(TaskStatusChanged $event): void
    {
        if ($event->wasCompleted || ! $event->isCompleted) {
            return;
        }

        $this->events->dispatch(new TaskCompleted($event->task, $event->to, $event->actor));
    }
}
