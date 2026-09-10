<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RecordsSchedulerRun;
use App\Jobs\RecalculateProjectMetrics;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;

/**
 * Refreshes the denormalised progress and health caches.
 *
 * Also the repair tool: when an import, a manual database edit or a failed job leaves a
 * project showing the wrong percentage, this is what puts it right — which is why it takes a
 * `--workspace` filter rather than only ever running across everything.
 */
final class RecalculateMetrics extends Command
{
    use RecordsSchedulerRun;

    protected $signature = 'planvio:recalculate-metrics
        {--workspace= : Limit the run to one workspace id}';

    protected $description = 'Recalculate project progress, milestone progress and project health.';

    public function handle(BusDispatcher $bus): int
    {
        $option = $this->option('workspace');
        $workspaceId = $option === null || $option === '' ? null : (int) $option;

        /** @var array{projects: int, milestones: int} $counts */
        $counts = $bus->dispatchNow(new RecalculateProjectMetrics($workspaceId));

        $this->components->info(__('Recalculated :projects projects and :milestones milestones.', [
            'projects' => $counts['projects'],
            'milestones' => $counts['milestones'],
        ]));

        $this->recordSchedulerRun('metrics', $counts);

        return self::SUCCESS;
    }
}
