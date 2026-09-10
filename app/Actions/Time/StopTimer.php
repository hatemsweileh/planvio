<?php

declare(strict_types=1);

namespace App\Actions\Time;

use App\Events\Time\TimerStopped;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Close a running timer and write the minutes it counted.
 *
 * An entry that is not running is returned untouched — stopping a stopped timer is the
 * state the caller asked for, not an error, and a second click must not overwrite the
 * minutes already recorded.
 *
 * A timer left running overnight is capped rather than trusted. Nobody worked 40 hours in
 * one sitting; the true window is kept in the activity properties so the entry can be
 * corrected by hand, but the stored duration stays inside what a day can hold, because
 * every report downstream sums this column.
 */
final class StopTimer
{
    public const MAX_MINUTES = 1440;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(TimeEntry $entry, User $actor): TimeEntry
    {
        if ($entry->is_running !== true) {
            return $entry;
        }

        $endedAt = Carbon::now();
        $startedAt = $entry->started_at instanceof Carbon ? $entry->started_at : $endedAt;

        $elapsed = max(0, $startedAt->diffInSeconds($endedAt));
        // Part of a minute still counts as a minute: a two-second timer that recorded zero
        // would look like nothing happened.
        $counted = max(1, (int) ceil($elapsed / 60));
        $minutes = min($counted, self::MAX_MINUTES);

        DB::transaction(function () use ($entry, $actor, $endedAt, $minutes, $counted): void {
            $entry->ended_at = $endedAt;
            $entry->minutes = $minutes;
            $entry->is_running = false;
            $entry->save();

            $this->activity->forUser($actor)->log($entry, 'timer_stopped', [
                'minutes' => $minutes,
                'elapsed_minutes' => $counted,
                'capped' => $counted > $minutes,
            ]);
        });

        $this->events->dispatch(new TimerStopped($entry, $actor, $minutes));

        return $entry->refresh();
    }
}
