<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Models\RecurringTask;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Edits a repetition rule: the shape of the tasks it produces, the schedule, or whether it
 * runs at all.
 *
 * Changing the schedule moves the cursor with it. `next_run_on` is recomputed from the new
 * rule *relative to the last occurrence already generated*, not from scratch: a rule that
 * has produced sixteen tasks and is now weekly instead of daily should fire next week, not
 * replay its own history. A rule that has never fired starts at its first occurrence.
 *
 * Reactivating a paused rule recomputes the cursor for the same reason, so a rule switched
 * back on after a month does not immediately generate the month of backlog it was paused to
 * avoid.
 */
final class UpdateRecurringTask
{
    public function __construct(
        private readonly RecurrenceCalculator $calculator,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(
        RecurringTask $rule,
        User $actor,
        ?RecurringTaskTemplate $template = null,
        ?RecurrenceSchedule $schedule = null,
        ?bool $isActive = null,
    ): RecurringTask {
        if ($template === null && $schedule === null && $isActive === null) {
            return $rule;
        }

        $attributes = [];

        if ($template !== null) {
            if (trim($template->title) === '') {
                throw InvalidRecurrence::titleRequired();
            }

            $attributes['template'] = $template->toArray();
        }

        if ($schedule !== null) {
            $this->assertUsable($schedule);

            $attributes = [...$attributes, ...$schedule->toColumns()];
            $attributes['next_run_on'] = $this->cursor($rule, $schedule)?->toDateString();
        }

        if ($isActive !== null) {
            $attributes['is_active'] = $isActive;

            if ($isActive && ! $rule->is_active) {
                $resumed = $schedule ?? RecurrenceSchedule::fromModel($rule);
                $attributes['next_run_on'] = $this->cursor($rule, $resumed)?->toDateString();
            }
        }

        $previousNextRun = $rule->next_run_on?->toDateString();

        return DB::transaction(function () use ($rule, $attributes, $actor, $previousNextRun): RecurringTask {
            $rule->fill($attributes);

            if (! $rule->isDirty()) {
                return $rule;
            }

            $changed = array_keys($rule->getDirty());
            $rule->save();

            $this->activity->record($rule, 'updated', $actor, [
                'changed' => $changed,
                'is_active' => $rule->is_active,
                'old_next_run_on' => $previousNextRun,
                'new_next_run_on' => $rule->next_run_on?->toDateString(),
            ]);

            return $rule;
        });
    }

    /**
     * Where the rule should pick up: after whatever it last produced, or at its beginning.
     */
    private function cursor(RecurringTask $rule, RecurrenceSchedule $schedule): ?CarbonImmutable
    {
        $last = $rule->last_run_on;

        return $last === null
            ? $this->calculator->first($schedule)
            : $this->calculator->next($schedule, RecurrenceSchedule::normalise($last));
    }

    private function assertUsable(RecurrenceSchedule $schedule): void
    {
        if ($schedule->interval < 1) {
            throw InvalidRecurrence::intervalTooSmall($schedule->interval);
        }

        if ($schedule->endsOn !== null && $schedule->endsOn->lessThan($schedule->startsOn)) {
            throw InvalidRecurrence::endsBeforeStart();
        }

        if ($schedule->maxOccurrences !== null && $schedule->maxOccurrences < 1) {
            throw InvalidRecurrence::maxOccurrencesTooSmall($schedule->maxOccurrences);
        }
    }
}
