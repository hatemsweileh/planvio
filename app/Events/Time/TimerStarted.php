<?php

declare(strict_types=1);

namespace App\Events\Time;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A running timer was opened.
 *
 * `stopped` carries the entry that was closed to make room for it, since a person may only
 * have one timer running at a time.
 */
final class TimerStarted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TimeEntry $entry,
        public readonly User $actor,
        public readonly ?TimeEntry $stopped = null,
    ) {}
}
