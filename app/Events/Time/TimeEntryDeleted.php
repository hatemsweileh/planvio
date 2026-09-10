<?php

declare(strict_types=1);

namespace App\Events\Time;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A logged entry was removed. `time_entries` has no soft deletes, so the model handed to
 * listeners is the last copy of the row.
 */
final class TimeEntryDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TimeEntry $entry,
        public readonly User $actor,
    ) {}
}
