<?php

declare(strict_types=1);

namespace App\Events\Time;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A logged entry was corrected.
 */
final class TimeEntryUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    public function __construct(
        public readonly TimeEntry $entry,
        public readonly User $actor,
        public readonly array $changes = [],
    ) {}
}
