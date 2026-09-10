<?php

declare(strict_types=1);

namespace App\Events\Time;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Time was entered after the fact rather than timed.
 */
final class TimeLogged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TimeEntry $entry,
        public readonly User $actor,
    ) {}
}
