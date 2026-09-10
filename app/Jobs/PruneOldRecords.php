<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retention, applied to the four tables that grow without limit.
 *
 * Each has its own window in `config('planvio.retention')`, and **0 means keep forever** —
 * the shipped default for the activity feed, because a project's history is the one thing an
 * install cannot reconstruct.
 *
 * Deletion is done in bounded batches of ids rather than one `DELETE ... WHERE created_at <`.
 * On the shared hosting Planvio targets, a single statement removing two years of rows holds
 * locks for as long as it takes and can time out halfway through with nothing to show for it;
 * a batch that fails costs one batch. `MAX_PER_TABLE` then caps the whole run, so a first
 * prune on a long-neglected install spreads over several nights instead of monopolising one.
 *
 * The query builder is used deliberately rather than Eloquent: pruning must not fire model
 * events, must not respect the workspace scope, and must not hydrate a hundred thousand
 * models to throw them away.
 */
final class PruneOldRecords implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const BATCH = 1000;

    /** Rows removed from any one table in a single run. */
    private const MAX_PER_TABLE = 100_000;

    /**
     * table => [retention config key, primary key column]
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const TABLES = [
        'activities' => ['activity_days', 'id'],
        'notifications' => ['notification_days', 'id'],
        'webhook_deliveries' => ['webhook_delivery_days', 'id'],
        'audit_logs' => ['audit_days', 'id'],
    ];

    /**
     * @param bool $dryRun count what would go without deleting anything
     */
    public function __construct(private readonly bool $dryRun = false) {}

    /**
     * @return array<string, int> rows removed (or matched, when dry running) per table
     */
    public function handle(): array
    {
        $removed = [];

        foreach (self::TABLES as $table => [$configKey, $primaryKey]) {
            $days = (int) config('planvio.retention.'.$configKey, 0);

            if ($days <= 0) {
                continue;
            }

            $removed[$table] = $this->prune($table, $primaryKey, Carbon::now()->subDays($days));
        }

        return $removed;
    }

    private function prune(string $table, string $primaryKey, Carbon $cutoff): int
    {
        if ($this->dryRun) {
            return (int) DB::table($table)->where('created_at', '<', $cutoff)->count();
        }

        $deleted = 0;

        while ($deleted < self::MAX_PER_TABLE) {
            $ids = DB::table($table)
                ->where('created_at', '<', $cutoff)
                ->orderBy($primaryKey)
                ->limit(self::BATCH)
                ->pluck($primaryKey)
                ->all();

            if ($ids === []) {
                break;
            }

            // Delete by primary key rather than by date: the second statement then touches
            // exactly the rows the first one saw, whatever is being written concurrently.
            $deleted += DB::table($table)->whereIn($primaryKey, $ids)->delete();

            if (count($ids) < self::BATCH) {
                break;
            }
        }

        return $deleted;
    }
}
