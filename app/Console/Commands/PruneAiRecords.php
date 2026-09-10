<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RecordsSchedulerRun;
use App\Enums\AiRunStatus;
use App\Enums\ToolRunStatus;
use App\Models\AiConversation;
use App\Models\AiMemory;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Applies each workspace's `ai_settings.retention_days` to the AI tables.
 *
 * Retention here is per tenant, not per installation: one workspace may be under a policy that
 * says ninety days while another keeps everything, and a workspace with no row of its own
 * inherits the global default. A null or zero window means keep forever, which is the shipped
 * default — an AI run is the evidence for every change the agent made, and an install cannot
 * reconstruct it.
 *
 * ## What is never pruned, and why
 *
 * **Runs that have not finished, and runs with a decision still outstanding.** A tool call
 * sitting at `pending_approval` — or approved and not yet executed — is live work. Deleting its
 * run would cascade the `ai_tool_runs` row away and destroy the request a person is being asked
 * to answer, which is both a broken UI and a hole in the audit trail exactly where approvals
 * are supposed to be strongest. `ai:expire-approvals` is what resolves those; only once it has,
 * and the run is terminal, does the run become eligible here.
 *
 * **`ai_usage_daily`.** The rollup holds no workspace content — five integers and a model name
 * — it is tiny, and it is the only long-term record of what the installation spent. Retention
 * exists to stop content and audit detail accumulating, not to erase the usage history the
 * moment it becomes interesting.
 *
 * **Conversations that still exist.** Only ones a person already deleted are removed for good.
 *
 * Expired memories are a separate matter and are removed regardless of any retention window:
 * `ai_memories.expires_at` is the memory's own promise about how long it lasts.
 *
 * Deletion is batched by primary key rather than done as one `DELETE ... WHERE created_at <`.
 * On shared hosting a single statement removing a year of rows holds locks for as long as it
 * takes and can time out with nothing to show for it; a batch that fails costs one batch.
 */
final class PruneAiRecords extends Command
{
    use RecordsSchedulerRun;

    private const BATCH = 500;

    /** Rows removed from any one table in a single run, so a first prune spreads over nights. */
    private const MAX_PER_TABLE = 50_000;

    protected $signature = 'ai:prune
        {--dry-run : Report what would be removed without deleting anything}';

    protected $description = 'Apply each workspace\'s AI retention window to runs, tool runs, conversations and memories.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $removed = [
            'ai_runs' => 0,
            'ai_conversations' => 0,
            'ai_memories' => $this->pruneExpiredMemories($dryRun, $now),
        ];

        foreach ($this->workspacesByRetention() as $days => $workspaceIds) {
            $cutoff = $now->copy()->subDays($days);

            $removed['ai_runs'] += $this->pruneRuns($workspaceIds, $cutoff, $dryRun);
            $removed['ai_conversations'] += $this->pruneDeletedConversations($workspaceIds, $cutoff, $dryRun);
        }

        $removed = array_filter($removed, static fn (int $count): bool => $count > 0);

        if ($removed === []) {
            $this->components->info(__('ai.console.nothing_to_prune'));

            if (! $dryRun) {
                $this->recordSchedulerRun('ai-prune', []);
            }

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
            $this->recordSchedulerRun('ai-prune', $removed);
        }

        return self::SUCCESS;
    }

    /* ------------------------------------------------------------------ *
     * Retention resolution
     * ------------------------------------------------------------------ */

    /**
     * Workspace ids grouped by the retention window that governs them, skipping every workspace
     * set to keep forever.
     *
     * Grouping rather than iterating one workspace at a time keeps this to a handful of
     * statements on an install with many tenants, since the window is usually the inherited
     * global one for all of them.
     *
     * @return array<int, list<int>> days => workspace ids
     */
    private function workspacesByRetention(): array
    {
        $global = self::window(
            AiSetting::query()->whereNull('workspace_id')->value('retention_days'),
        );

        /** @var array<int, int|null> $own */
        $own = AiSetting::query()
            ->whereNotNull('workspace_id')
            ->pluck('retention_days', 'workspace_id')
            ->map(static fn (mixed $days): ?int => self::window($days))
            ->all();

        $grouped = [];

        foreach (Workspace::query()->withTrashed()->pluck('id') as $id) {
            $workspaceId = (int) $id;
            $days = array_key_exists($workspaceId, $own) ? $own[$workspaceId] : $global;

            if ($days === null) {
                continue;
            }

            $grouped[$days][] = $workspaceId;
        }

        return $grouped;
    }

    /**
     * A usable retention window in days, or null for "keep forever".
     */
    private static function window(mixed $days): ?int
    {
        if (is_string($days) && ctype_digit($days)) {
            $days = (int) $days;
        }

        return is_int($days) && $days > 0 ? $days : null;
    }

    /* ------------------------------------------------------------------ *
     * The tables
     * ------------------------------------------------------------------ */

    /**
     * Runs that finished before the cutoff, carrying their tool runs with them by cascade.
     *
     * @param list<int> $workspaceIds
     */
    private function pruneRuns(array $workspaceIds, Carbon $cutoff, bool $dryRun): int
    {
        $eligible = fn (): Builder => AiRun::withoutWorkspaceScope()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('created_at', '<', $cutoff)
            ->whereIn('status', self::terminalRunStatuses())
            // The approval guard. A run whose tool call is still awaiting a decision, or has
            // been approved and not yet executed, is live work whatever its age.
            ->whereDoesntHave('toolRuns', static function (Builder $outstanding): void {
                $outstanding
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->whereIn('status', [
                        ToolRunStatus::PendingApproval->value,
                        ToolRunStatus::Approved->value,
                    ]);
            });

        if ($dryRun) {
            return $eligible()->count();
        }

        return $this->deleteInBatches($eligible);
    }

    /**
     * Conversations a person deleted, removed for good once the window has passed. Their
     * messages go with them by cascade.
     *
     * @param list<int> $workspaceIds
     */
    private function pruneDeletedConversations(array $workspaceIds, Carbon $cutoff, bool $dryRun): int
    {
        $eligible = fn (): Builder => AiConversation::withoutWorkspaceScope()
            ->onlyTrashed()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('deleted_at', '<', $cutoff);

        if ($dryRun) {
            return $eligible()->count();
        }

        return $this->deleteInBatches($eligible, forceDelete: true);
    }

    /**
     * Memories past their own `expires_at`, in every workspace and regardless of retention.
     */
    private function pruneExpiredMemories(bool $dryRun, Carbon $now): int
    {
        $eligible = fn (): Builder => AiMemory::withoutWorkspaceScope()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now);

        if ($dryRun) {
            return $eligible()->count();
        }

        return $this->deleteInBatches($eligible);
    }

    /* ------------------------------------------------------------------ *
     * Batching
     * ------------------------------------------------------------------ */

    /**
     * Delete everything the builder matches, a bounded page of primary keys at a time.
     *
     * The second statement targets exactly the rows the first one saw, so whatever is being
     * written concurrently cannot widen the delete.
     *
     * @param callable(): Builder $eligible
     */
    private function deleteInBatches(callable $eligible, bool $forceDelete = false): int
    {
        $deleted = 0;

        while ($deleted < self::MAX_PER_TABLE) {
            /** @var list<int> $ids */
            $ids = $eligible()
                ->orderBy('id')
                ->limit(self::BATCH)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            $batch = $eligible()->whereKey($ids);

            $deleted += $forceDelete ? (int) $batch->forceDelete() : (int) $batch->delete();

            if (count($ids) < self::BATCH) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * @return list<string>
     */
    private static function terminalRunStatuses(): array
    {
        return array_values(array_map(
            static fn (AiRunStatus $status): string => $status->value,
            array_filter(
                AiRunStatus::cases(),
                static fn (AiRunStatus $status): bool => $status->isTerminal(),
            ),
        ));
    }
}
