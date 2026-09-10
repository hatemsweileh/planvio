<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Task;
use App\Models\TimeEntry;

/**
 * A time entry cannot be written as asked.
 *
 * Durations are whole minutes (ARCHITECTURE.md §5.5) and a single entry is capped at one
 * day: an entry longer than that is a forgotten running timer, not work, and it would
 * distort every report it appears in.
 */
final class InvalidTimeEntry extends DomainException
{
    public static function minutesOutOfRange(int $minutes): self
    {
        return new self(
            __('actions.time.minutes_out_of_range'),
            ['minutes' => $minutes],
        );
    }

    public static function notRunning(TimeEntry $entry): self
    {
        return new self(
            __('actions.time.not_running'),
            ['time_entry_id' => (int) $entry->getKey()],
        );
    }

    public static function isRunning(TimeEntry $entry): self
    {
        return new self(
            __('actions.time.is_running'),
            ['time_entry_id' => (int) $entry->getKey()],
        );
    }

    public static function taskInAnotherProject(Task $task, int $projectId): self
    {
        return new self(
            __('actions.time.task_in_another_project'),
            [
                'task_id' => (int) $task->getKey(),
                'task_project_id' => (int) $task->project_id,
                'project_id' => $projectId,
            ],
        );
    }

    public static function futureDate(string $date): self
    {
        return new self(
            __('actions.time.future_date'),
            ['spent_on' => $date],
        );
    }
}
