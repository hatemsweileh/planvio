<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Automations\AutomationRunner;
use App\Console\Commands\Concerns\RecordsSchedulerRun;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * The scheduler's entry point into {@see AutomationRunner}.
 *
 * All of the interesting behaviour — the claim, the acting authority, the schedule arithmetic,
 * the failure backoff — belongs to the runner. This is the way in, plus the two things a person
 * running it by hand wants: a moment to pretend it is, and a way to see what would be picked up
 * without picking it up.
 *
 * `--dry-run` matters more here than it does for most commands. An automation is a standing
 * instruction to change real records, and the first question anybody has before switching the
 * scheduler on is "what exactly is about to run?". Listing the due automations answers it
 * without starting anything.
 */
final class RunAiAutomations extends Command
{
    use RecordsSchedulerRun;

    protected $signature = 'ai:run-automations
        {--at= : Treat this moment as now (any parseable date/time), for catching up by hand}
        {--dry-run : List the automations that are due without claiming or starting anything}';

    protected $description = 'Start the AI automations whose schedule has come due.';

    public function handle(AutomationRunner $runner): int
    {
        if (! $runner->enabled()) {
            $this->components->info(__('ai.console.automations_disabled'));

            return self::SUCCESS;
        }

        $at = $this->moment();

        if ($at === false) {
            $this->components->error(__('ai.console.unreadable_moment'));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->report($runner, $at);
        }

        $started = $runner->runDue($at);

        $this->components->info(__('ai.console.automations_started', ['count' => $started]));

        $this->recordSchedulerRun('ai-automations', ['started' => $started]);

        return self::SUCCESS;
    }

    private function report(AutomationRunner $runner, ?Carbon $at): int
    {
        $due = $runner->due($at);

        if ($due->isEmpty()) {
            $this->components->info(__('ai.console.nothing_due'));

            return self::SUCCESS;
        }

        $this->table(
            [__('Workspace'), __('Automation'), __('Due'), __('Mode'), __('Acting as')],
            $due->map(static fn ($automation): array => [
                (string) ($automation->workspace?->name ?? '—'),
                (string) $automation->name,
                (string) ($automation->next_run_at?->toDateTimeString() ?? '—'),
                (string) ($automation->mode?->value ?? '—'),
                (string) ($automation->creator?->name ?? '—'),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @return Carbon|null|false null for "now", false when the option cannot be read
     */
    private function moment(): Carbon|null|false
    {
        $option = $this->option('at');

        if (! is_string($option) || $option === '') {
            return null;
        }

        try {
            return Carbon::parse($option);
        } catch (\Throwable) {
            return false;
        }
    }
}
