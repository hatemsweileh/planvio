<?php

declare(strict_types=1);

namespace App\Ai\Approvals;

use App\Ai\Support\Redactor;
use App\Enums\AiRunStatus;
use App\Enums\Permission;
use App\Enums\ToolRunStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\Ai\AiApprovalRequired;
use App\Services\NotificationDispatcher;
use App\Support\CurrentWorkspace;
use App\Support\Permissions;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * The human decision in the middle of an agent run.
 *
 * A tool call that the policy engine will not auto-execute stops here: the run is parked at
 * `awaiting_approval`, an `ai_tool_runs` row records the tool, its redacted arguments and the
 * blast radius, and everybody who may approve is told. Nothing executes until a named person
 * says yes (AI_SECURITY.md, "Approval gating").
 *
 * Three properties are the point of this class.
 *
 * **Approving is an authorization, not a state change.** `approve()` and `reject()` go through
 * `Gate::forUser($approver)` against `AiToolRunPolicy`, which resolves the approver's own
 * membership and `ai.approve` — the same authority the UI uses. There is no path that records
 * an approval without one.
 *
 * **Approving twice executes once.** The transition is a single conditional UPDATE against
 * `status = pending_approval`. Whichever request wins gets one row back and is the only one
 * that resumes the run; every other caller — a double-click, two approvers racing, a retried
 * job — sees zero rows and returns quietly. The decision that is already recorded stands, and
 * the tool runs a single time.
 *
 * **A recorded approval is not a licence to run later.** Resumption is refused while the
 * workspace kill switch is engaged or AI is switched off, and `ElevatedTool::approvalGate()`
 * independently re-checks, at execution time, that the approval belongs to this run, this tool
 * and these exact arguments, and is younger than `config('ai.approvals.approval_ttl_minutes')`.
 * {@see expire()} sweeps the ones nobody answered.
 */
final class ApprovalService
{
    /** Fallback when `config('ai.approvals.approval_ttl_minutes')` is missing or unusable. */
    private const DEFAULT_TTL_MINUTES = 1440;

    /** `ai_tool_runs.rejected_reason` is a varchar(255); leave room for the ellipsis. */
    private const MAX_REASON_CHARS = 240;

    /**
     * The resumer is optional so that recording a decision never depends on the agent loop
     * being wired up: with nothing bound the run is left `queued` for a worker to collect.
     */
    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly ?ResumesApprovedRuns $resumer = null,
    ) {}

    /* ------------------------------------------------------------------ *
     * Asking
     * ------------------------------------------------------------------ */

    /**
     * Park $toolRun for a human decision and tell the people who can make it.
     *
     * $consequences is the tool's own count of what the call would affect
     * (`ReportsConsequences::consequences()`), rendered into the row so the approval card
     * states a measured blast radius rather than a sentence the model wrote. It is redacted
     * and truncated on the way in like every other AI-derived string.
     *
     * `ai_tool_runs.status` has no "proposed" case, so the runner writes the row as
     * `pending_approval` and hands it here to be described and announced. A row in any other
     * status has already been decided — approved, rejected, executed — and is left exactly as
     * it is: asking again must never demote a decision somebody already made.
     *
     * @param array<string, scalar|null|array<array-key, mixed>> $consequences
     */
    public function request(AiToolRun $toolRun, array $consequences): void
    {
        $status = $toolRun->status;

        if ($status !== null && $status !== ToolRunStatus::PendingApproval) {
            return;
        }

        $toolRun->forceFill([
            'status' => ToolRunStatus::PendingApproval,
            'approval_required' => true,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_reason' => null,
            'result_summary' => $this->describeConsequences($toolRun, $consequences),
        ])->save();

        $run = $this->runOf($toolRun);

        if ($run !== null && ! $run->isTerminal()) {
            $run->status = AiRunStatus::AwaitingApproval;
            $run->save();
        }

        $workspace = $this->workspaceOf($toolRun);

        if ($run === null || $workspace === null) {
            return;
        }

        $this->notifyApprovers($workspace, $run, $toolRun);
    }

    /* ------------------------------------------------------------------ *
     * Deciding
     * ------------------------------------------------------------------ */

    /**
     * Record $approver's approval and put the run back on the queue.
     *
     * @throws AuthorizationException when the approver does not hold `ai.approve` here
     */
    public function approve(AiToolRun $toolRun, User $approver): void
    {
        $workspace = $this->workspaceOf($toolRun);

        $this->authorize($workspace, $approver, 'approve', $toolRun, __('You cannot approve AI actions here.'));

        $claimed = $this->transition($toolRun, ToolRunStatus::Approved, [
            'approved_by' => $approver->getKey(),
            'approved_at' => Carbon::now(),
            'rejected_reason' => null,
        ]);

        // Somebody already decided this one. Their decision stands and the tool runs once.
        if (! $claimed) {
            return;
        }

        $toolRun->refresh();

        $run = $this->runOf($toolRun);

        if ($run === null) {
            return;
        }

        // An approval granted while the workspace is stopped is recorded but not acted on:
        // the kill switch outranks it, and the run ends where it stood (ARCHITECTURE.md §7.7).
        if (! $this->mayResume($workspace)) {
            if (! $run->isTerminal()) {
                $run->markFinished(
                    AiRunStatus::Cancelled,
                    __('Approved, but the run did not resume: AI is stopped for this workspace.'),
                );
            }

            return;
        }

        if ($run->status === AiRunStatus::AwaitingApproval) {
            $run->status = AiRunStatus::Queued;
            $run->save();
        }

        $this->resumer?->resume($run, $toolRun);
    }

    /**
     * Refuse $toolRun and stop the run. Nothing is changed and nothing is retried.
     *
     * @throws AuthorizationException when the user does not hold `ai.approve` here
     */
    public function reject(AiToolRun $toolRun, User $user, string $reason): void
    {
        $workspace = $this->workspaceOf($toolRun);

        $this->authorize($workspace, $user, 'reject', $toolRun, __('You cannot decide AI actions here.'));

        $claimed = $this->transition($toolRun, ToolRunStatus::Rejected, [
            'rejected_reason' => $this->shortReason($reason),
            'approved_by' => null,
            'approved_at' => null,
        ]);

        if (! $claimed) {
            return;
        }

        $toolRun->refresh();

        $this->stopRun($toolRun, __('Stopped: :tool was rejected.', ['tool' => (string) $toolRun->tool]));
    }

    /* ------------------------------------------------------------------ *
     * Sweeping
     * ------------------------------------------------------------------ */

    /**
     * Close every approval nobody answered inside
     * `config('ai.approvals.approval_ttl_minutes')`, and stop the runs waiting on them.
     *
     * Expiry is recorded as a rejection with a stated reason rather than as a silent skip: a
     * request that timed out was not granted, and the audit trail should say so. It also
     * matches what the execution gate already believes — an approval older than the TTL stops
     * licensing the call whether or not this sweep has run.
     *
     * @return int the number of approvals expired
     */
    public function expire(): int
    {
        $cutoff = Carbon::now()->subMinutes($this->ttlMinutes());
        $expired = 0;

        $reason = __('The approval request expired before anybody decided it.');

        AiToolRun::withoutWorkspaceScope()
            ->pendingApproval()
            ->where('created_at', '<', $cutoff)
            ->chunkById(100, function (Collection $rows) use (&$expired, $reason): void {
                foreach ($rows as $toolRun) {
                    $claimed = $this->transition($toolRun, ToolRunStatus::Rejected, [
                        'rejected_reason' => $this->shortReason($reason),
                        'approved_by' => null,
                        'approved_at' => null,
                    ]);

                    if (! $claimed) {
                        continue;
                    }

                    $expired++;

                    $toolRun->refresh();

                    $this->stopRun($toolRun, $reason);
                }
            });

        return $expired;
    }

    /* ------------------------------------------------------------------ *
     * Internals — decisions
     * ------------------------------------------------------------------ */

    /**
     * The one place a pending approval changes state.
     *
     * Conditional on `status = pending_approval`, so exactly one caller can win: the update
     * reports one affected row for the decision that took effect and zero for every repeat.
     *
     * @param array<string, mixed> $attributes
     * @return bool whether this caller is the one that decided it
     */
    private function transition(AiToolRun $toolRun, ToolRunStatus $to, array $attributes): bool
    {
        $affected = AiToolRun::withoutWorkspaceScope()
            ->whereKey($toolRun->getKey())
            ->where('status', ToolRunStatus::PendingApproval->value)
            ->update($attributes + [
                'status' => $to->value,
                'approval_required' => true,
                'updated_at' => Carbon::now(),
            ]);

        $claimed = $affected === 1;

        /*
         * A parked row holds its arguments verbatim so the approver reviews, and the runner
         * replays, exactly what will execute (see AgentRunner's approval gate). Once the
         * decision is made the row is pure audit trail.
         *
         * On approval the runner redacts when the call completes. On rejection or expiry
         * nothing else ever touches the row, so it has to happen here — otherwise a rejected
         * call keeps unredacted arguments forever, which is the opposite of what refusing it
         * was meant to achieve.
         *
         * Deliberately a second statement rather than part of the UPDATE above: that update
         * is the atomic claim, and it stays a pure conditional so approving twice still
         * executes once.
         */
        if ($claimed && $to !== ToolRunStatus::Approved) {
            $this->redactStoredArguments($toolRun);
        }

        return $claimed;
    }

    private function redactStoredArguments(AiToolRun $toolRun): void
    {
        $stored = AiToolRun::withoutWorkspaceScope()->find($toolRun->getKey());

        if (! $stored instanceof AiToolRun || ! is_array($stored->arguments)) {
            return;
        }

        $stored->arguments = app(Redactor::class)->redactArray($stored->arguments);
        $stored->saveQuietly();
    }

    /**
     * Close the run a decided-against tool call was blocking. A run that already finished is
     * left alone.
     */
    private function stopRun(AiToolRun $toolRun, string $summary): void
    {
        $run = $this->runOf($toolRun);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        $run->markFinished(AiRunStatus::Cancelled, $summary);
    }

    /**
     * Whether an approved call may actually restart the loop. The master switch, the workspace
     * switch and the kill switch all outrank a recorded approval.
     */
    private function mayResume(?Workspace $workspace): bool
    {
        if ($workspace === null || ! (bool) config('ai.enabled')) {
            return false;
        }

        $settings = AiSetting::forWorkspace($workspace);

        return $settings !== null
            && $settings->is_enabled
            && ! $settings->kill_switch_engaged;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(?Workspace $workspace, User $user, string $ability, AiToolRun $toolRun, string $message): void
    {
        $allowed = $this->inWorkspace(
            $workspace,
            static fn (): bool => Gate::forUser($user)->allows($ability, $toolRun),
        );

        if (! $allowed) {
            throw new AuthorizationException($message);
        }
    }

    /* ------------------------------------------------------------------ *
     * Internals — people
     * ------------------------------------------------------------------ */

    /**
     * Tell everyone who can decide this call that it is waiting.
     *
     * The requester is included when they can approve: `ai.approve` is held by owners, admins
     * and — inside their own projects — managers, and a manager approving a call their own
     * request produced is the ordinary case rather than an escalation.
     */
    private function notifyApprovers(Workspace $workspace, AiRun $run, AiToolRun $toolRun): void
    {
        $approvers = $this->approvers($workspace, $toolRun);

        if ($approvers === []) {
            return;
        }

        $this->notifications->send(
            recipients: $approvers,
            notification: new AiApprovalRequired($run, $toolRun),
            category: 'ai.approval_required',
            actor: $run->loadMissing('user')->user,
            workspace: $workspace,
            includeActor: true,
        );
    }

    /**
     * The members who may approve $toolRun.
     *
     * The role query is a cheap candidate filter — the capability matrix says which roles can
     * ever hold `ai.approve` — and the Gate is the authority: a manager only holds it inside
     * projects they manage, and `AiToolRunPolicy` is what resolves that.
     *
     * @return list<User>
     */
    private function approvers(Workspace $workspace, AiToolRun $toolRun): array
    {
        $roles = array_values(array_map(
            static fn (WorkspaceRole $role): string => $role->value,
            array_filter(
                WorkspaceRole::cases(),
                static fn (WorkspaceRole $role): bool => Permissions::has($role, Permission::AiApprove),
            ),
        ));

        if ($roles === []) {
            return [];
        }

        $candidates = User::query()
            ->where('is_active', true)
            ->whereIn(
                'id',
                WorkspaceMember::withoutWorkspaceScope()
                    ->where('workspace_id', $workspace->getKey())
                    ->whereIn('role', $roles)
                    ->select('user_id'),
            )
            ->get();

        return $this->inWorkspace($workspace, static fn (): array => array_values(array_filter(
            $candidates->all(),
            static fn (User $user): bool => Gate::forUser($user)->allows('approve', $toolRun),
        )));
    }

    /* ------------------------------------------------------------------ *
     * Internals — records and text
     * ------------------------------------------------------------------ */

    private function runOf(AiToolRun $toolRun): ?AiRun
    {
        $runId = $toolRun->ai_run_id;

        if ($runId === null) {
            return null;
        }

        return AiRun::withoutWorkspaceScope()->find($runId);
    }

    private function workspaceOf(AiToolRun $toolRun): ?Workspace
    {
        $workspaceId = $toolRun->workspace_id;

        if ($workspaceId === null) {
            return null;
        }

        return Workspace::query()->find($workspaceId);
    }

    /**
     * Bind $workspace for the duration of $callback, the way an AI run does, and restore
     * whatever was bound before. Policies re-resolve membership themselves; the binding only
     * gives the class-level fallbacks a tenant to judge against.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    private function inWorkspace(?Workspace $workspace, Closure $callback): mixed
    {
        if ($workspace === null) {
            return $callback();
        }

        return Container::getInstance()
            ->make(CurrentWorkspace::class)
            ->runFor($workspace, static fn (): mixed => $callback());
    }

    /**
     * The blast radius as one readable line, for the approval card and the tool-run log.
     *
     * Values come from a tool, which read them from workspace records, so they go through the
     * redactor before they are stored: an approval card is not a place to discover a token
     * somebody pasted into a project name.
     *
     * @param array<string, scalar|null|array<array-key, mixed>> $consequences
     */
    private function describeConsequences(AiToolRun $toolRun, array $consequences): ?string
    {
        $limit = $this->summaryChars();
        $redactor = new Redactor(maxChars: $limit);

        $parts = [];

        foreach ($redactor->redactArray($consequences) as $key => $value) {
            $rendered = $this->renderValue($value);

            if ($rendered === null) {
                continue;
            }

            $parts[] = str_replace('_', ' ', (string) $key).': '.$rendered;
        }

        // Most tools cannot count a blast radius — creating a task affects nothing that
        // exists yet — and a blank card reads as a bug rather than as "nothing else is
        // touched". Saying what is waiting is the minimum a person needs.
        if ($parts === []) {
            return Str::limit(
                __('Waiting for a human decision before :tool runs.', ['tool' => (string) $toolRun->tool]),
                $limit,
            );
        }

        return Str::limit(
            __('Awaiting approval — :tool would affect: :details', [
                'tool' => (string) $toolRun->tool,
                'details' => implode(', ', $parts),
            ]),
            $limit,
        );
    }

    private function renderValue(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? __('yes') : __('no'),
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => trim($value) === '' ? null : Str::limit(trim($value), 120),
            is_array($value) => (string) count($value),
            default => null,
        };
    }

    private function shortReason(string $reason): ?string
    {
        $clean = trim((new Redactor(maxChars: self::MAX_REASON_CHARS))->redactString($reason));

        return $clean === '' ? null : Str::limit($clean, self::MAX_REASON_CHARS);
    }

    private function summaryChars(): int
    {
        $configured = config('ai.logging.max_stored_summary_chars');

        return is_int($configured) && $configured > 0 ? $configured : 1000;
    }

    private function ttlMinutes(): int
    {
        $configured = config('ai.approvals.approval_ttl_minutes');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_TTL_MINUTES;
    }
}
