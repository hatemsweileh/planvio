<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Recurring\GenerateDueRecurringTasks;
use App\Console\Commands\Concerns\RecordsSchedulerRun;
use App\Models\Workspace;
use Illuminate\Console\Command;

/**
 * Mints the occurrences that have come due from every active recurrence rule.
 *
 * The action does the interesting work — row locks, per-tenant binding, bounded catch-up —
 * so this is only the scheduler's way in, plus the two things a person running it by hand
 * wants: a date to pretend it is, and a workspace to limit it to.
 *
 * `--as-of` is what makes a missed week recoverable without editing the database: the action
 * generates every occurrence up to the date given, capped per rule, so a server that was down
 * catches up over the next few ticks instead of losing the occurrences entirely.
 */
final class GenerateRecurringTasks extends Command
{
    use RecordsSchedulerRun;

    protected $signature = 'planvio:generate-recurring-tasks
        {--as-of= : Generate everything due on or before this date (Y-m-d), defaults to today}
        {--workspace= : Limit the run to one workspace id}';

    protected $description = 'Create the tasks that recurring schedules have come due for.';

    public function handle(GenerateDueRecurringTasks $generate): int
    {
        $asOf = $this->option('as-of');
        $asOf = is_string($asOf) && $asOf !== '' ? $asOf : null;

        $workspace = $this->workspace();

        if ($workspace === false) {
            $this->components->error(__('No workspace with that id.'));

            return self::FAILURE;
        }

        $created = $generate($asOf, $workspace);

        $this->components->info(__('Created :count recurring tasks.', ['count' => $created]));

        $this->recordSchedulerRun('recurring', ['created' => $created]);

        return self::SUCCESS;
    }

    /**
     * @return Workspace|null|false the workspace, null for "all", false when the id is unknown
     */
    private function workspace(): Workspace|null|false
    {
        $option = $this->option('workspace');

        if ($option === null || $option === '') {
            return null;
        }

        // Not workspace-scoped itself, but the lookup is a system one either way.
        $workspace = Workspace::query()->find((int) $option);

        return $workspace instanceof Workspace ? $workspace : false;
    }
}
