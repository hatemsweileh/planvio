<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RecordsSchedulerRun;
use App\Jobs\SendDueDateReminders;
use App\Jobs\SendOverdueNotices;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;

/**
 * The hourly reminder pass: what is coming up, and what has slipped.
 *
 * Hourly rather than daily because workspaces keep their own timezones — the jobs skip any
 * workspace whose local clock is not at its digest hour, so each one hears from Planvio once,
 * in its own morning. `--now` overrides that gate, which is what makes the feature testable
 * by hand at four in the afternoon.
 *
 * The jobs are run inline (`dispatchNow`) rather than pushed onto the queue. The scheduler is
 * already a background context, the work is bounded, and running it here means the command
 * can report what it actually sent instead of reporting that it queued something which may or
 * may not have worked. `dispatchNow` rather than `dispatchSync` on purpose: the latter routes
 * a `ShouldQueue` job through the sync connection, which discards the handler's return value.
 */
final class SendReminders extends Command
{
    use RecordsSchedulerRun;

    protected $signature = 'planvio:send-reminders
        {--now : Ignore each workspace\'s digest hour and send immediately}
        {--workspace= : Limit the run to one workspace id}
        {--skip-overdue : Send only the upcoming-deadline reminders}';

    protected $description = 'Send due-soon reminders and overdue notices.';

    public function handle(BusDispatcher $bus): int
    {
        $force = (bool) $this->option('now');
        $workspaceId = $this->workspaceId();

        /** @var int $dueSoon */
        $dueSoon = $bus->dispatchNow(new SendDueDateReminders($force, $workspaceId));

        $overdue = 0;

        if (! $this->option('skip-overdue')) {
            /** @var int $overdue */
            $overdue = $bus->dispatchNow(new SendOverdueNotices($force, $workspaceId));
        }

        $this->components->info(__('Reminders sent: :due upcoming, :overdue overdue.', [
            'due' => $dueSoon,
            'overdue' => $overdue,
        ]));

        $this->recordSchedulerRun('reminders', [
            'due_soon' => $dueSoon,
            'overdue' => $overdue,
        ]);

        return self::SUCCESS;
    }

    private function workspaceId(): ?int
    {
        $option = $this->option('workspace');

        if ($option === null || $option === '') {
            return null;
        }

        return (int) $option;
    }
}
