<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RecordsSchedulerRun;
use App\Jobs\PruneOldRecords;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;

/**
 * Applies `config('planvio.retention')` to the tables that grow without limit.
 *
 * `--dry-run` counts instead of deleting, which is the only responsible way to introduce a
 * retention window on an install that has never had one: the first real run of a 120-day
 * notification policy on a three-year-old database removes a great deal, and an administrator
 * should see the number before it happens rather than after.
 */
final class PruneRecords extends Command
{
    use RecordsSchedulerRun;

    protected $signature = 'planvio:prune
        {--dry-run : Report what would be removed without deleting anything}';

    protected $description = 'Delete activity, notification, webhook and audit records past their retention window.';

    public function handle(BusDispatcher $bus): int
    {
        $dryRun = (bool) $this->option('dry-run');

        /** @var array<string, int> $removed */
        $removed = $bus->dispatchNow(new PruneOldRecords($dryRun));

        if ($removed === []) {
            $this->components->info(__('Nothing to prune: every retention window is set to keep forever.'));

            $this->recordSchedulerRun('prune', []);

            return self::SUCCESS;
        }

        $this->table(
            [__('Table'), $dryRun ? __('Would remove') : __('Removed')],
            array_map(
                static fn (string $table, int $count): array => [$table, (string) $count],
                array_keys($removed),
                array_values($removed),
            ),
        );

        if (! $dryRun) {
            $this->recordSchedulerRun('prune', $removed);
        }

        return self::SUCCESS;
    }
}
