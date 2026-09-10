<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Registers a rule that mints tasks on a schedule.
 *
 * The rule is validated to the point of producing at least one occurrence before it is
 * stored. A schedule that can never fire — an end date before the start, a custom filter no
 * date satisfies — would otherwise sit in the table forever, picked up by every cron tick
 * and generating nothing, with no signal that it is broken.
 *
 * `next_run_on` is computed here rather than left null, so the scheduler's index
 * (`workspace_id, is_active, next_run_on`) can find the rule from the first tick onwards.
 */
final class CreateRecurringTask
{
    public function __construct(
        private readonly RecurrenceCalculator $calculator,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(
        Project $project,
        RecurringTaskTemplate $template,
        RecurrenceSchedule $schedule,
        User $actor,
        bool $isActive = true,
    ): RecurringTask {
        $this->assertUsable($template, $schedule);

        $first = $this->calculator->first($schedule);

        if ($first === null) {
            throw InvalidRecurrence::neverOccurs();
        }

        return DB::transaction(function () use ($project, $template, $schedule, $actor, $isActive, $first): RecurringTask {
            $rule = RecurringTask::query()->create([
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'template' => $template->toArray(),
                ...$schedule->toColumns(),
                'next_run_on' => $first->toDateString(),
                'last_run_on' => null,
                'occurrences_generated' => 0,
                'is_active' => $isActive,
                'created_by' => $actor->getKey(),
            ]);

            $this->activity->record($rule, 'created', $actor, [
                'title' => $template->title,
                'frequency' => $schedule->frequency->value,
                'interval' => $schedule->interval,
                'starts_on' => $schedule->startsOn->toDateString(),
                'next_run_on' => $first->toDateString(),
            ]);

            return $rule;
        });
    }

    private function assertUsable(RecurringTaskTemplate $template, RecurrenceSchedule $schedule): void
    {
        if (trim($template->title) === '') {
            throw InvalidRecurrence::titleRequired();
        }

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
