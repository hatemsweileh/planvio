<?php

declare(strict_types=1);

namespace App\Events\Time;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A running timer was closed and its minutes written.
 */
final class TimerStopped implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TimeEntry $entry,
        public readonly User $actor,
        public readonly int $minutes,
    ) {}
}
