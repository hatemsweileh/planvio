<?php

declare(strict_types=1);

namespace App\Actions\Time;

use App\Events\Time\TimeLogged;
use App\Exceptions\InvalidTimeEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Record time that was worked without being timed.
 *
 * Durations are whole minutes (ARCHITECTURE.md §5.5) and one entry covers at most one day:
 * a longer span is a data-entry slip, and letting it through corrupts every capacity,
 * budget and invoice figure derived from this table.
 *
 * "Today" is the user's today. Somebody in Auckland logging work on their Monday evening
 * is not logging it for the UTC Monday, and rejecting it as future-dated would be wrong.
 */
final class LogTime
{
    public const MAX_MINUTES = 1440;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        User $user,
        Project $project,
        int $minutes,
        DateTimeInterface|string|null $spentOn = null,
        ?Task $task = null,
        ?string $description = null,
        bool $isBillable = true,
    ): TimeEntry {
        if ($minutes < 1 || $minutes > self::MAX_MINUTES) {
            throw InvalidTimeEntry::minutesOutOfRange($minutes);
        }

        if ($task !== null && (int) $task->project_id !== (int) $project->getKey()) {
            throw InvalidTimeEntry::taskInAnotherProject($task, (int) $project->getKey());
        }

        $date = self::resolveDate($user, $spentOn);

        $entry = DB::transaction(function () use (
            $user,
            $project,
            $task,
            $minutes,
            $date,
            $description,
            $isBillable,
        ): TimeEntry {
            $entry = TimeEntry::query()->create([
                'workspace_id' => (int) $project->workspace_id,
                'project_id' => $project->getKey(),
                'task_id' => $task?->getKey(),
                'user_id' => $user->getKey(),
                'minutes' => $minutes,
                'description' => self::trimDescription($description),
                'spent_on' => $date,
                'started_at' => null,
                'ended_at' => null,
                'is_running' => false,
                'is_billable' => $isBillable,
            ]);

            $this->activity->forUser($user)->log($entry, 'time_logged', [
                'project_id' => (int) $project->getKey(),
                'task_id' => $task === null ? null : (int) $task->getKey(),
                'minutes' => $minutes,
                'spent_on' => $date,
                'is_billable' => $isBillable,
            ]);

            return $entry;
        });

        $this->events->dispatch(new TimeLogged($entry, $user));

        return $entry;
    }

    /**
     * @return string the day, as `Y-m-d`
     */
    public static function resolveDate(User $user, DateTimeInterface|string|null $spentOn): string
    {
        $timezone = (string) ($user->timezone ?? 'UTC');
        $timezone = $timezone === '' ? 'UTC' : $timezone;

        $today = Carbon::now($timezone)->startOfDay();

        if ($spentOn === null) {
            return $today->toDateString();
        }

        $date = $spentOn instanceof DateTimeInterface
            ? Carbon::instance($spentOn)->setTimezone($timezone)->startOfDay()
            : Carbon::parse($spentOn, $timezone)->startOfDay();

        if ($date->greaterThan($today)) {
            throw InvalidTimeEntry::futureDate($date->toDateString());
        }

        return $date->toDateString();
    }

    public static function trimDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }

        $trimmed = trim($description);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 255);
    }
}
