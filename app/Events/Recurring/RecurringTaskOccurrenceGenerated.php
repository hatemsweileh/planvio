<?php

declare(strict_types=1);

namespace App\Events\Recurring;

use App\Models\RecurringTask;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The scheduler minted one occurrence of a recurring rule.
 *
 * The occurrence date is not necessarily today: a cron that missed a few ticks catches up,
 * and every caught-up occurrence carries the date it was scheduled for.
 */
final class RecurringTaskOccurrenceGenerated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly RecurringTask $recurringTask,
        public readonly Task $task,
        public readonly CarbonImmutable $occurrenceOn,
    ) {}
}
