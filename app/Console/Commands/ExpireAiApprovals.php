<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RecordsSchedulerRun;
use App\Enums\AiRunStatus;
use App\Enums\ToolRunStatus;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Closes approval requests nobody answered, and the runs waiting behind them.
 *
 * A tool call parked at `pending_approval` is a proposal to change something, made in a context
 * that has since moved on: the task it was going to update may have been closed, reassigned or
 * deleted in the day since the agent proposed it. Executing it a week later because somebody
 * finally clicked approve would be acting on stale intent, so `config('ai.approvals.approval_ttl_minutes')`
 * puts a shelf life on the offer.
 *
 * Expiry fails closed. The call is marked `rejected` — never approved, never skipped quietly —
 * with the reason recorded on the row, so the tool-run log reads "this was proposed and it did
 * not happen, and here is why" rather than leaving a decision that was never made looking like
 * one that was.
 *
 * The run behind it is then cancelled, but only when nothing is left for it to wait on. A run
 * stuck at `awaiting_approval` forever is not merely untidy: it is not terminal, so it blocks
 * its automation from ever being due again, and it keeps its tool runs out of reach of the
 * retention window.
 */
final class ExpireAiApprovals extends Command
{
    use RecordsSchedulerRun;

    /** Rows touched in one pass, so a long-neglected install cannot monopolise a tick. */
    private const MAX_PER_RUN = 5000;

    protected $signature = 'ai:expire-approvals
        {--minutes= : Override the configured time-to-live, in minutes}
        {--dry-run : Report what would expire without changing anything}';

    protected $description = 'Reject AI approval requests that have gone unanswered past their time-to-live.';

    public function handle(): int
    {
        if (! (bool) config('ai.enabled', false)) {
            $this->components->info(__('ai.console.ai_disabled'));

            return self::SUCCESS;
        }

        $minutes = $this->minutes();

        if ($minutes === false) {
            $this->components->error(__('ai.console.unreadable_minutes'));

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subMinutes($minutes);
        $dryRun = (bool) $this->option('dry-run');

        /** @var list<int> $expiring */
        $expiring = AiToolRun::withoutWorkspaceScope()
            ->where('status', ToolRunStatus::PendingApproval->value)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($expiring === []) {
            $this->components->info(__('ai.console.no_approvals_expired'));

            if (! $dryRun) {
                $this->recordSchedulerRun('ai-approvals', ['expired' => 0, 'runs_closed' => 0]);
            }

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->components->info(__('ai.console.approvals_would_expire', [
                'count' => count($expiring),
                'minutes' => $minutes,
            ]));

            return self::SUCCESS;
        }

        $runIds = AiToolRun::withoutWorkspaceScope()
            ->whereKey($expiring)
            ->pluck('ai_run_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $expired = AiToolRun::withoutWorkspaceScope()
            ->whereKey($expiring)
            // Re-asserted, so an approval granted between the read above and this write is not
            // overwritten by the expiry.
            ->where('status', ToolRunStatus::PendingApproval->value)
            ->update([
                'status' => ToolRunStatus::Rejected->value,
                'rejected_reason' => __('ai.approvals.expired', ['minutes' => $minutes]),
            ]);

        $closed = $this->closeAbandonedRuns($runIds);

        $this->components->info(__('ai.console.approvals_expired', [
            'count' => $expired,
            'runs' => $closed,
        ]));

        $this->recordSchedulerRun('ai-approvals', [
            'expired' => $expired,
            'runs_closed' => $closed,
        ]);

        return self::SUCCESS;
    }

    /**
     * Cancel the runs whose last outstanding decision has just expired.
     *
     * Only runs that were actually waiting are touched, and only when no tool call of theirs is
     * still pending or approved-but-unexecuted — an approved call is work that is still owed,
     * and cancelling the run out from under it would strand it.
     *
     * @param list<int> $runIds
     */
    private function closeAbandonedRuns(array $runIds): int
    {
        if ($runIds === []) {
            return 0;
        }

        $closed = 0;

        $runs = AiRun::withoutWorkspaceScope()
            ->whereKey($runIds)
            ->where('status', AiRunStatus::AwaitingApproval->value)
            ->whereDoesntHave('toolRuns', static function (Builder $outstanding): void {
                $outstanding
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->whereIn('status', [
                        ToolRunStatus::PendingApproval->value,
                        ToolRunStatus::Approved->value,
                    ]);
            })
            ->get();

        foreach ($runs as $run) {
            $run->markFinished(AiRunStatus::Cancelled, __('ai.approvals.run_abandoned'));
            $closed++;
        }

        return $closed;
    }

    /**
     * @return int|false the time-to-live in minutes, or false when the option is unusable
     */
    private function minutes(): int|false
    {
        $option = $this->option('minutes');

        if (is_string($option) && $option !== '') {
            return ctype_digit($option) && (int) $option > 0 ? (int) $option : false;
        }

        $configured = config('ai.approvals.approval_ttl_minutes', 1440);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 1440;
    }
}
