<?php

declare(strict_types=1);

namespace App\Actions\Time;

use App\Events\Time\TimerStarted;
use App\Exceptions\InvalidTimeEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Open a running timer for somebody.
 *
 * A person can only be doing one thing at a time, so starting a timer closes whatever was
 * already running — including a timer left open in a different workspace, which is exactly
 * the case a workspace-scoped query would miss and which is why the lookup deliberately
 * steps outside the tenant scope.
 *
 * Starting the timer that is already running on the same work returns it untouched rather
 * than restarting it: a double-clicked play button must not throw away the minutes already
 * counted.
 */
final class StartTimer
{
    public function __construct(
        private readonly StopTimer $stop,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        User $user,
        Project $project,
        ?Task $task = null,
        ?string $description = null,
        bool $isBillable = true,
    ): TimeEntry {
        if ($task !== null && (int) $task->project_id !== (int) $project->getKey()) {
            throw InvalidTimeEntry::taskInAnotherProject($task, (int) $project->getKey());
        }

        $running = self::runningFor($user);

        if ($running instanceof TimeEntry && self::isSameWork($running, $project, $task)) {
            return $running;
        }

        $stopped = $running instanceof TimeEntry ? ($this->stop)($running, $user) : null;

        $entry = DB::transaction(function () use ($user, $project, $task, $description, $isBillable): TimeEntry {
            $entry = TimeEntry::query()->create([
                'workspace_id' => (int) $project->workspace_id,
                'project_id' => $project->getKey(),
                'task_id' => $task?->getKey(),
                'user_id' => $user->getKey(),
                'minutes' => 0,
                'description' => self::trimDescription($description),
                'spent_on' => self::todayFor($user),
                'started_at' => Carbon::now(),
                'ended_at' => null,
                'is_running' => true,
                'is_billable' => $isBillable,
            ]);

            $this->activity->forUser($user)->log($entry, 'timer_started', [
                'project_id' => (int) $project->getKey(),
                'task_id' => $task === null ? null : (int) $task->getKey(),
            ]);

            return $entry;
        });

        $this->events->dispatch(new TimerStarted($entry, $user, $stopped));

        return $entry;
    }

    /**
     * The user's open timer, wherever it is. One person, one clock — so this looks past the
     * bound workspace on purpose (ARCHITECTURE.md §3 allows the escape hatch for exactly
     * this kind of cross-tenant system rule).
     */
    public static function runningFor(User $user): ?TimeEntry
    {
        return TimeEntry::withoutWorkspaceScope()
            ->where('user_id', $user->getKey())
            ->where('is_running', true)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    private static function isSameWork(TimeEntry $entry, Project $project, ?Task $task): bool
    {
        return (int) $entry->project_id === (int) $project->getKey()
            && (int) ($entry->task_id ?? 0) === (int) ($task?->getKey() ?? 0);
    }

    private static function todayFor(User $user): string
    {
        $timezone = (string) ($user->timezone ?? 'UTC');

        return Carbon::now($timezone === '' ? 'UTC' : $timezone)->toDateString();
    }

    private static function trimDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
