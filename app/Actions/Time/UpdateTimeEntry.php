<?php

declare(strict_types=1);

namespace App\Actions\Time;

use App\Events\Time\TimeEntryUpdated;
use App\Exceptions\InvalidTimeEntry;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use DateTimeInterface;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Correct a recorded time entry.
 *
 * A full replacement: every mutable attribute is written from the arguments, so a caller
 * that omits one is clearing it, not leaving it alone. That is the honest shape for an edit
 * form, which always submits the whole entry.
 *
 * A running timer cannot be edited. Its minutes are computed when it stops, so anything
 * written here would be overwritten a moment later — the caller is told to stop it first
 * rather than being allowed to make a change that silently evaporates.
 */
final class UpdateTimeEntry
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        TimeEntry $entry,
        User $actor,
        int $minutes,
        DateTimeInterface|string|null $spentOn = null,
        ?string $description = null,
        bool $isBillable = true,
        ?Task $task = null,
    ): TimeEntry {
        if ($entry->is_running === true) {
            throw InvalidTimeEntry::isRunning($entry);
        }

        if ($minutes < 1 || $minutes > LogTime::MAX_MINUTES) {
            throw InvalidTimeEntry::minutesOutOfRange($minutes);
        }

        if ($task !== null && (int) $task->project_id !== (int) $entry->project_id) {
            throw InvalidTimeEntry::taskInAnotherProject($task, (int) $entry->project_id);
        }

        // The entry's owner decides which day it belongs to, not whoever is editing it.
        $owner = $entry->relationLoaded('user') && $entry->user instanceof User ? $entry->user : $actor;
        $date = LogTime::resolveDate($owner, $spentOn ?? $entry->spent_on);

        $entry->minutes = $minutes;
        $entry->spent_on = $date;
        $entry->description = LogTime::trimDescription($description);
        $entry->is_billable = $isBillable;
        $entry->task_id = $task?->getKey();

        $changes = ActivityLogger::changes($entry);

        if ($changes === []) {
            return $entry;
        }

        DB::transaction(function () use ($entry, $actor, $changes): void {
            $entry->save();

            $this->activity->forUser($actor)->log($entry, 'time_updated', $changes);
        });

        $this->events->dispatch(new TimeEntryUpdated($entry, $actor, $changes));

        return $entry->refresh();
    }
}
