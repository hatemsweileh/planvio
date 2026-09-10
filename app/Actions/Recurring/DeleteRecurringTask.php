<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Models\RecurringTask;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a repetition rule.
 *
 * The tasks it already produced are left alone, and the schema is what makes that safe:
 * `tasks.recurring_task_id` is `nullOnDelete`, so every occurrence survives with its link
 * cleared. That is the right outcome — those tasks are real work, some of it done, and
 * deleting a schedule is a statement about the future rather than about the past.
 *
 * Pausing is the other half of this pair and lives in {@see UpdateRecurringTask}: switching
 * `is_active` off stops the generator while keeping the rule and its cursor, which is what
 * somebody wants nine times in ten. Deleting is for the rule that was a mistake.
 *
 * The activity entry is written before the row goes, so the feed keeps a record of what the
 * rule was — a deletion with no description of the deleted thing tells nobody anything.
 */
final class DeleteRecurringTask
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(RecurringTask $rule, User $actor): void
    {
        $template = RecurringTaskTemplate::fromArray($rule->template ?? []);

        DB::transaction(function () use ($rule, $actor, $template): void {
            $this->activity->record($rule, 'deleted', $actor, [
                'title' => $template->title,
                'frequency' => $rule->frequency->value,
                'interval' => (int) $rule->interval,
                'occurrences_generated' => (int) $rule->occurrences_generated,
            ]);

            $rule->delete();
        });
    }
}
