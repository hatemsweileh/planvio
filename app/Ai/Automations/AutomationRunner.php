<?php

declare(strict_types=1);

namespace App\Ai\Automations;

use App\Ai\AiGate;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiTrigger;
use App\Enums\AutomationTrigger;
use App\Models\AiAutomation;
use App\Models\AiRun;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The scheduler's way into the agent: find what is due, claim it exactly once, start a run
 * under the authority of the person who created the automation, and put the schedule forward.
 *
 * ## Authority
 *
 * An automation runs as `created_by` and nobody else. There is no service account and no
 * elevated path (AI_SECURITY.md), so a standing instruction can never do more than the person
 * who wrote it could do by hand — and it stops working the moment their permissions are
 * withdrawn. {@see AiGate} is consulted for that person on every tick, not once at creation.
 *
 * ## The overlap guard
 *
 * Planvio targets shared hosting, where the cron entry is the only scheduler available and
 * overlapping ticks are normal: a tick that runs long is simply joined by the next one a
 * minute later. Two ticks that both decide an automation is due would run the agent twice
 * against the same objective, which for a mutating automation means duplicated work.
 *
 * The guard is {@see self::claim()} and it is a *single conditional UPDATE*. Not a select
 * followed by a save — that is the classic check-then-act race, and it loses precisely when it
 * matters, because both ticks read "unlocked" before either writes. Instead the freshness test
 * lives in the `WHERE` clause, so the database decides, and the affected-row count is the
 * answer: exactly one statement can match a row that no live lock covers, and every other
 * statement matches nothing. The in-memory model's attributes are never consulted — they may
 * be seconds stale, which is exactly the window the race lives in.
 *
 * The lock carries an expiry (`config('ai.automations.lock_ttl_seconds')`) rather than being
 * held until an explicit release, because a worker killed mid-run cannot release anything. A
 * run is bounded well below the TTL, so a lock still held at expiry means the process holding
 * it is gone.
 *
 * A second, independent guard sits alongside it: an automation with a run that has not reached
 * a terminal status is not due. That is what stops an expired lock from stacking a second run
 * on top of one still queued, and what stops an automation waiting on a human approval from
 * piling up more approval requests behind it.
 */
final class AutomationRunner
{
    /**
     * How much wider than the per-tick budget {@see self::runDue()} reads.
     *
     * Rows can be lost between the read and the claim — another tick got there first — so the
     * tick reads a few more candidates than it intends to start rather than coming back empty
     * because the first three were taken.
     */
    private const CANDIDATE_HEADROOM = 4;

    public function __construct(
        private readonly AiGate $gate,
        /**
         * Bound by the agent-loop slice. Null on an installation where the loop is not wired
         * up yet: the run is still recorded and left `queued` for a worker to collect.
         */
        private readonly ?StartsAgentRuns $starter = null,
    ) {}

    /* ------------------------------------------------------------------ *
     * Selection
     * ------------------------------------------------------------------ */

    /**
     * Scheduled automations that are due, unclaimed, not already running, and whose workspace
     * may currently use AI.
     *
     * The workspace test is applied as a filter on the query rather than to the rows that come
     * back, because a workspace with AI switched off would otherwise sit at the head of the
     * due list forever and starve everything behind it out of the per-tick budget. Resolving
     * it costs one settings lookup per *workspace* with due work, not per automation.
     *
     * @return Collection<int, AiAutomation>
     */
    public function due(?Carbon $at = null, ?int $limit = null): Collection
    {
        $moment = $at ?? Carbon::now();

        /** @var list<int> $workspaceIds */
        $workspaceIds = $this->dueQuery($moment)
            ->distinct()
            ->pluck('workspace_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $allowed = $this->workspacesAllowingAi($workspaceIds);

        if ($allowed === []) {
            /** @var Collection<int, AiAutomation> */
            return new Collection;
        }

        $query = $this->dueQuery($moment)
            ->whereIn('workspace_id', $allowed)
            ->with(['workspace', 'creator'])
            ->orderBy('next_run_at')
            ->orderBy('id');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        return $query->get();
    }

    /* ------------------------------------------------------------------ *
     * The claim
     * ------------------------------------------------------------------ */

    /**
     * Take the lock on $automation, returning whether this caller got it.
     *
     * One statement. The row is matched only while it is active and covered by no live lock,
     * and the lock is written in the same statement that tests for it, so two concurrent ticks
     * cannot both come away with `true`: the database applies them in some order, the first
     * updates one row, and the second matches none because the row it wanted now carries a
     * lock that expires in the future.
     *
     * Deliberately *not* `$automation->locked_until` — reading the lock state off the model
     * would reintroduce the check-then-act race the statement exists to close. The model is
     * only used for its primary key, and its lock attributes are refreshed afterwards from
     * what was actually written.
     *
     * "Covered by no live lock" means: no token, or no recorded expiry, or an expiry already
     * passed. The middle case cannot arise from this class — token and expiry are always
     * written together — and treating it as free is what stops a hand-edited row from wedging
     * an automation permanently.
     */
    public function claim(AiAutomation $automation): bool
    {
        $now = Carbon::now();
        $token = Str::random(64);
        $until = $now->copy()->addSeconds($this->lockTtlSeconds());

        $claimed = AiAutomation::withoutWorkspaceScope()
            ->whereKey($automation->getKey())
            ->where('is_active', true)
            ->where(static function (Builder $free) use ($now): void {
                $free
                    ->whereNull('lock_token')
                    ->orWhereNull('locked_until')
                    ->orWhere('locked_until', '<=', $now);
            })
            ->update([
                'lock_token' => $token,
                'locked_until' => $until,
            ]);

        if ($claimed !== 1) {
            return false;
        }

        // Reflect what the database now holds, so a caller that goes on to release the lock is
        // working from the claim it actually took.
        $automation->forceFill([
            'lock_token' => $token,
            'locked_until' => $until,
        ])->syncOriginal();

        return true;
    }

    /**
     * Give the lock back and record what happened.
     *
     * Clears the claim, stamps `last_run_at` / `last_run_status`, counts the run, advances the
     * schedule and maintains the consecutive-failure streak. Read and write happen inside one
     * transaction against a freshly loaded row: the streak is a read-modify-write, and the
     * in-memory copy was loaded before the run started.
     *
     * A failing automation backs off along `config('ai.automations.failure_backoff_minutes')`
     * and is switched off entirely after `disable_after_consecutive_failures`. Both exist for
     * the same reason: an automation whose objective the agent cannot satisfy will fail again
     * next tick, and an unbounded retry against a paid provider is the expensive kind of bug.
     * Deactivation is visible and reversible in the UI; silently draining a budget is neither.
     */
    public function release(AiAutomation $automation, AiRunStatus $status): void
    {
        $now = Carbon::now();

        $attributes = DB::transaction(function () use ($automation, $status, $now): ?array {
            // The workspace comes with it because the schedule is computed in its timezone.
            $fresh = AiAutomation::withoutWorkspaceScope()
                ->with('workspace')
                ->lockForUpdate()
                ->find($automation->getKey());

            if ($fresh === null) {
                return null;
            }

            $failures = $this->failureStreak($fresh, $status);
            $exhausted = $failures >= $this->disableAfterFailures();

            $attributes = [
                'lock_token' => null,
                'locked_until' => null,
                'last_run_at' => $now,
                'last_run_status' => $status->value,
                'run_count' => (int) $fresh->run_count + 1,
                'failure_count' => $failures,
                'is_active' => $exhausted ? false : (bool) $fresh->is_active,
                'next_run_at' => $exhausted
                    ? null
                    : $this->nextRunAt($fresh, $now, $this->backoffMinutes($status, $failures)),
            ];

            AiAutomation::withoutWorkspaceScope()
                ->whereKey($fresh->getKey())
                ->update($attributes);

            return $attributes;
        });

        if ($attributes !== null) {
            $automation->forceFill($attributes)->syncOriginal();
        }
    }

    /* ------------------------------------------------------------------ *
     * The tick
     * ------------------------------------------------------------------ */

    /**
     * Claim up to `config('ai.automations.max_per_tick')` due automations and start a run for
     * each. Returns how many runs were started.
     *
     * The per-tick cap is a shared-hosting concession, not a scheduling policy: a workspace
     * with forty due automations gets three now and the rest over the following ticks, instead
     * of forty agent loops queued behind one another in the same minute.
     */
    public function runDue(?Carbon $at = null): int
    {
        if (! $this->enabled()) {
            return 0;
        }

        $budget = $this->maxPerTick();

        if ($budget < 1) {
            return 0;
        }

        $started = 0;

        foreach ($this->due($at, $budget * self::CANDIDATE_HEADROOM) as $automation) {
            if ($started >= $budget) {
                break;
            }

            if (! $this->claim($automation)) {
                // Another tick got there first. That is the guard working, not a failure.
                continue;
            }

            if ($this->start($automation)) {
                $started++;
            }
        }

        return $started;
    }

    /* ------------------------------------------------------------------ *
     * Starting one run
     * ------------------------------------------------------------------ */

    /**
     * Record and queue the run for an automation this tick has already claimed.
     *
     * Returns false when nothing was started, in which case the claim has been released and
     * the outcome recorded — either as a refusal the administrator can read in the run log, or
     * as a failed run.
     */
    private function start(AiAutomation $automation): bool
    {
        $workspace = $automation->workspace;
        $actingUser = $automation->creator;

        if (! $workspace instanceof Workspace || ! $actingUser instanceof User) {
            // The tenant or the author is gone. Nothing can act for them, and re-checking every
            // minute would be pointless, so the automation is stood down.
            $this->deactivate($automation);

            return false;
        }

        // Asked for the acting user on every tick, never cached from when the automation was
        // written: a person who has lost `ai.use`, been deactivated or been removed from the
        // workspace takes their automations with them.
        $refusal = $this->gate->refusal($workspace, $actingUser);

        if ($refusal !== null) {
            $this->recordRefusal($automation, $workspace, $actingUser, $refusal);
            $this->release($automation, AiRunStatus::Failed);

            return false;
        }

        try {
            $run = $this->createRun($automation, $workspace, $actingUser, AiRunStatus::Queued);
        } catch (Throwable) {
            // The run row could not be written, so there is nothing to hand the loop. The
            // message is deliberately not kept: an exception from the storage layer can quote
            // a connection string (CLAUDE.md rule 4).
            $this->release($automation, AiRunStatus::Failed);

            return false;
        }

        // Onto the `ai` queue when the agent-loop slice is bound. When it is not, the run stays
        // `queued` and is picked up by a worker later; either way the automation is not due
        // again until that run reaches a terminal status.
        $this->starter?->start($run);

        return true;
    }

    /**
     * Write the `ai_runs` row for a refusal, so "this automation stopped working, and here is
     * the sentence explaining why" is visible in the run log rather than only in a counter.
     */
    private function recordRefusal(
        AiAutomation $automation,
        Workspace $workspace,
        User $actingUser,
        string $refusal,
    ): void {
        try {
            $run = $this->createRun($automation, $workspace, $actingUser, AiRunStatus::Failed);

            $run->forceFill([
                'error' => $refusal,
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
                'duration_ms' => 0,
            ])->save();
        } catch (Throwable) {
            // Recording why an automation was refused must never be the thing that breaks the
            // tick; the release below still happens.
        }
    }

    private function createRun(
        AiAutomation $automation,
        Workspace $workspace,
        User $actingUser,
        AiRunStatus $status,
    ): AiRun {
        $settings = $this->gate->settingsFor($workspace);
        $provider = $settings?->provider;

        $run = new AiRun;

        $run->forceFill([
            'workspace_id' => $workspace->getKey(),
            'project_id' => $automation->project_id,
            'ai_conversation_id' => null,
            // The authority the whole run borrows. Never a service account.
            'user_id' => $actingUser->getKey(),
            'trigger' => AiTrigger::Automation->value,
            // The automation's own mode, the workspace's effective one, and finally the mode
            // that can do the least. `ai_runs.mode` has no default, and a run that cannot say
            // what authority it was operating under has no business existing.
            'mode' => ($automation->mode ?? $settings?->effectiveMode() ?? AiMode::Assistant)->value,
            'objective' => $automation->objective,
            'status' => $status->value,
            'model' => $provider?->model,
            'ai_provider_id' => $provider?->getKey(),
            'ai_automation_id' => $automation->getKey(),
        ])->save();

        return $run;
    }

    /* ------------------------------------------------------------------ *
     * Scheduling
     * ------------------------------------------------------------------ */

    /**
     * When the automation should next be considered due.
     *
     * The cron expression is read in the *workspace's* timezone — "every Monday at 9" means
     * nine o'clock where the team is, not on the server — and the result is converted back to
     * the application timezone, which is what the `timestamp` column stores.
     *
     * `$backoffMinutes`, when set, is a floor rather than a replacement: the next run is the
     * later of the cron's answer and now-plus-backoff. A five-minute backoff must not pull a
     * monthly automation forward, and a monthly cron must not cancel out the backoff on an
     * hourly one.
     *
     * A missing or unparseable expression yields null, which takes the automation out of the
     * due list without deactivating it — the schedule is broken, and an administrator can fix
     * the expression without also having to remember to switch it back on.
     */
    private function nextRunAt(AiAutomation $automation, Carbon $after, ?int $backoffMinutes): ?Carbon
    {
        if ($automation->trigger_type !== AutomationTrigger::Schedule) {
            return null;
        }

        $expression = is_string($automation->schedule_cron) ? trim($automation->schedule_cron) : '';

        // Aliases such as `@daily` are resolved by the parser, so one validity check covers both
        // forms. Anything it refuses is a schedule that can never fire.
        if ($expression === '' || ! CronExpression::isValidExpression($expression)) {
            return null;
        }

        $earliest = $backoffMinutes === null
            ? $after->copy()
            : $after->copy()->addMinutes($backoffMinutes);

        $timezone = $this->timezoneOf($automation);

        try {
            $next = (new CronExpression($expression))->getNextRunDate(
                $earliest->copy()->setTimezone($timezone),
                0,
                false,
                $timezone,
            );
        } catch (Throwable) {
            return null;
        }

        $next = Carbon::instance($next)->setTimezone(self::applicationTimezone());

        return $next->lessThan($earliest) ? $earliest : $next;
    }

    /**
     * The workspace's timezone, or UTC when the stored value is not an identifier PHP knows.
     *
     * `workspaces.timezone` is free text; a typo there must not take down the scheduler.
     */
    private function timezoneOf(AiAutomation $automation): string
    {
        $timezone = $automation->workspace?->timezone;

        if (! is_string($timezone) || $timezone === '') {
            return 'UTC';
        }

        try {
            Carbon::now($timezone);

            return $timezone;
        } catch (Throwable) {
            return 'UTC';
        }
    }

    /* ------------------------------------------------------------------ *
     * Failure accounting
     * ------------------------------------------------------------------ */

    /**
     * The consecutive-failure count after a run finished in $status.
     *
     * `failed` and `limit_reached` are failures: in both cases the objective was not met and
     * the next tick will most likely repeat the outcome. `succeeded` and `partial` clear the
     * streak — partial did some of the work, which is not a fault. Everything else (a run
     * cancelled by a person, a run parked awaiting approval) leaves the streak alone: neither
     * outcome says anything about whether the automation itself is healthy.
     */
    private function failureStreak(AiAutomation $automation, AiRunStatus $status): int
    {
        $current = max(0, (int) $automation->failure_count);

        return match ($status) {
            AiRunStatus::Failed, AiRunStatus::LimitReached => $current + 1,
            AiRunStatus::Succeeded, AiRunStatus::Partial => 0,
            default => $current,
        };
    }

    /**
     * Minutes to hold off after a failure, from `config('ai.automations.failure_backoff_minutes')`,
     * or null when this outcome is not a failure. The last configured value repeats once the
     * list runs out.
     */
    private function backoffMinutes(AiRunStatus $status, int $failures): ?int
    {
        if ($status !== AiRunStatus::Failed && $status !== AiRunStatus::LimitReached) {
            return null;
        }

        $steps = array_values(array_filter(
            (array) config('ai.automations.failure_backoff_minutes', []),
            static fn (mixed $minutes): bool => is_int($minutes) && $minutes > 0,
        ));

        if ($steps === []) {
            return null;
        }

        /** @var list<int> $steps */
        return $steps[min(max(0, $failures - 1), count($steps) - 1)];
    }

    /**
     * Stand an automation down without counting it as a run: used when the workspace or the
     * author no longer exists, which no number of retries will fix.
     */
    private function deactivate(AiAutomation $automation): void
    {
        AiAutomation::withoutWorkspaceScope()
            ->whereKey($automation->getKey())
            ->update([
                'is_active' => false,
                'next_run_at' => null,
                'lock_token' => null,
                'locked_until' => null,
            ]);

        $automation->forceFill([
            'is_active' => false,
            'next_run_at' => null,
            'lock_token' => null,
            'locked_until' => null,
        ])->syncOriginal();
    }

    /* ------------------------------------------------------------------ *
     * Queries
     * ------------------------------------------------------------------ */

    /**
     * Active, scheduled, past due, unclaimed, and with nothing already in flight.
     *
     * The workspace scope is dropped explicitly rather than relied upon to be inert: this runs
     * from the console, where nothing is bound, and an authorization-adjacent query should not
     * depend on ambient state to be correct.
     *
     * @return Builder<AiAutomation>
     */
    private function dueQuery(Carbon $moment): Builder
    {
        return AiAutomation::withoutWorkspaceScope()
            ->where('is_active', true)
            ->where('trigger_type', AutomationTrigger::Schedule->value)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $moment)
            ->unlocked($moment)
            ->whereDoesntHave('runs', static function (Builder $inFlight): void {
                $inFlight
                    ->withoutGlobalScope(WorkspaceScope::class)
                    ->whereIn('status', self::unfinishedStatuses());
            });
    }

    /**
     * Of $workspaceIds, the ones that may currently use AI.
     *
     * @param list<int> $workspaceIds
     * @return list<int>
     */
    private function workspacesAllowingAi(array $workspaceIds): array
    {
        if ($workspaceIds === []) {
            return [];
        }

        $allowed = [];

        foreach (Workspace::query()->whereKey($workspaceIds)->get() as $workspace) {
            if ($this->gate->workspaceAllows($workspace)) {
                $allowed[] = (int) $workspace->getKey();
            }
        }

        return $allowed;
    }

    /**
     * @return list<string>
     */
    private static function unfinishedStatuses(): array
    {
        return array_values(array_map(
            static fn (AiRunStatus $status): string => $status->value,
            array_filter(
                AiRunStatus::cases(),
                static fn (AiRunStatus $status): bool => ! $status->isTerminal(),
            ),
        ));
    }

    /* ------------------------------------------------------------------ *
     * Configuration
     * ------------------------------------------------------------------ */

    public function enabled(): bool
    {
        return (bool) config('ai.enabled', false)
            && (bool) config('ai.automations.enabled', true);
    }

    private function maxPerTick(): int
    {
        $configured = config('ai.automations.max_per_tick', 3);

        return is_numeric($configured) ? (int) $configured : 3;
    }

    private function lockTtlSeconds(): int
    {
        $configured = config('ai.automations.lock_ttl_seconds', 900);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 900;
    }

    private function disableAfterFailures(): int
    {
        $configured = config('ai.automations.disable_after_consecutive_failures', 10);

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : 10;
    }

    private static function applicationTimezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : 'UTC';
    }
}
