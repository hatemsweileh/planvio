<?php

declare(strict_types=1);

namespace App\Actions\Time;

use App\Events\Time\TimeEntryDeleted;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Remove a time entry.
 *
 * `time_entries` carries no soft deletes — a deleted entry has to actually leave the
 * totals, or every report and invoice built on them is wrong — so the activity row is
 * written with everything needed to reconstruct what was removed.
 */
final class DeleteTimeEntry
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(TimeEntry $entry, User $actor): TimeEntry
    {
        if (! $entry->exists) {
            return $entry;
        }

        DB::transaction(function () use ($entry, $actor): void {
            // Written before the delete, and with the whole row in `properties`: the
            // subject id it points at is about to stop resolving, so the feed has to be
            // able to render the entry from the activity alone.
            $this->activity->forUser($actor)->record(
                subject: $entry,
                event: 'time_deleted',
                actor: $actor,
                properties: [
                    'time_entry_id' => (int) $entry->getKey(),
                    'project_id' => (int) $entry->project_id,
                    'task_id' => $entry->task_id === null ? null : (int) $entry->task_id,
                    'user_id' => (int) $entry->user_id,
                    'minutes' => (int) $entry->minutes,
                    'spent_on' => $entry->spent_on?->toDateString(),
                    'is_billable' => (bool) $entry->is_billable,
                ],
            );

            $entry->delete();
        });

        $this->events->dispatch(new TimeEntryDeleted($entry, $actor));

        return $entry;
    }
}
