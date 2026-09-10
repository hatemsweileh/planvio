<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai\Concerns;

use App\Ai\Approvals\ApprovalService;
use App\Enums\AiToolRisk;
use App\Enums\ToolRunStatus;
use App\Models\AiToolRun;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * The human decision, wherever it is taken.
 *
 * A parked call can be answered from three places — the approvals queue, the card inline in
 * the conversation, and the drawer — and all three must behave identically, because the one
 * thing worse than an approval screen is two approval screens that disagree. So the rules
 * live here.
 *
 * ## What the card shows, and why nothing is redacted
 *
 * A row waiting on a decision holds its arguments **verbatim**: that is deliberate, and
 * `tests/Feature/Security/AiApprovalFidelityTest.php` pins it. The runner replays those
 * exact arguments when the approval is granted, so the card has to render the same bytes —
 * showing a redacted or truncated version would mean the person authorised one thing and
 * the system carried out another. Redaction happens on the way to a terminal status, after
 * the decision, where it belongs.
 *
 * ## Why rejecting is one click and approving sometimes is not
 *
 * Refusing is always safe: nothing happens, the run stops, and a person who is unsure
 * should never be made to work for the safe outcome. Approving a `destructive` tool is the
 * opposite — it is the last moment before something is deleted or somebody is removed — so
 * it asks for the tool's own name to be typed. That is a speed bump on exactly the calls
 * `config('ai.approvals.always_require_approval')` already refuses to let any policy waive.
 *
 * ## The authorisation is asked twice on purpose
 *
 * This component asks the Gate before it calls the service, because a Livewire component
 * must never invoke behaviour it has not authorised. {@see ApprovalService} asks again,
 * because it is also reachable from the API and the console. Neither check is redundant to
 * the other; each is the only one on its own path.
 *
 * The using component must provide `protected function actor(): User`.
 */
trait DecidesApprovals
{
    /** Fallback matching {@see ApprovalService}, for a missing or unusable config value. */
    private const DEFAULT_TTL_MINUTES = 1440;

    /** A queue longer than this is a symptom, not a list; the per-row Gate check is bounded by it. */
    private const MAX_QUEUE_ROWS = 100;

    /** @var array<string, string> keyed by tool-run id, as Livewire sends array keys */
    public array $rejectionReasons = [];

    /** @var array<string, string> the typed confirmation for a destructive approval */
    public array $approvalConfirmations = [];

    /* ------------------------------------------------------------------ *
     * Deciding
     * ------------------------------------------------------------------ */

    public function approveToolRun(int $toolRunId): void
    {
        $toolRun = $this->pendingToolRun($toolRunId);

        if (! $toolRun instanceof AiToolRun) {
            return;
        }

        $this->authorize('approve', $toolRun);

        if ($this->needsTypedConfirmation($toolRun)) {
            $typed = trim($this->approvalConfirmations[(string) $toolRunId] ?? '');

            if ($typed !== (string) $toolRun->tool) {
                $this->addError(
                    'approval.'.$toolRunId,
                    __('Type :tool exactly to approve it. This action cannot be undone.', ['tool' => (string) $toolRun->tool]),
                );

                return;
            }
        }

        $this->resetErrorBag('approval.'.$toolRunId);

        app(ApprovalService::class)->approve($toolRun, $this->actor());

        unset($this->approvalConfirmations[(string) $toolRunId]);

        $this->dispatch('planvio-notify', type: 'success', message: __('Approved. The run has gone back on the queue.'));

        $this->afterApprovalDecision($toolRun->refresh());
    }

    public function rejectToolRun(int $toolRunId): void
    {
        $toolRun = $this->pendingToolRun($toolRunId);

        if (! $toolRun instanceof AiToolRun) {
            return;
        }

        $this->authorize('reject', $toolRun);

        $reason = trim($this->rejectionReasons[(string) $toolRunId] ?? '');

        app(ApprovalService::class)->reject(
            $toolRun,
            $this->actor(),
            $reason === '' ? __('Rejected without a stated reason.') : $reason,
        );

        unset($this->rejectionReasons[(string) $toolRunId], $this->approvalConfirmations[(string) $toolRunId]);

        $this->dispatch('planvio-notify', type: 'success', message: __('Rejected. Nothing was changed and the run has stopped.'));

        $this->afterApprovalDecision($toolRun->refresh());
    }

    /* ------------------------------------------------------------------ *
     * Reading
     * ------------------------------------------------------------------ */

    /**
     * Everything in this workspace this person may decide, oldest first — the one closest to
     * expiring is the one that needs answering.
     *
     * The Gate filters the rows rather than the query, because `ai.approve` is refined by
     * project for a workspace manager and only `AiToolRunPolicy` knows that refinement.
     * Listing a call somebody cannot act on would make the queue a list of other people's
     * homework; the cap keeps the per-row check bounded.
     *
     * @return EloquentCollection<int, AiToolRun>
     */
    #[Computed]
    public function pendingApprovals(): EloquentCollection
    {
        $actor = $this->actor();

        $rows = AiToolRun::query()
            ->pendingApproval()
            ->with([
                'run:id,uuid,objective,mode,trigger,status,user_id',
                'run.user:id,name,avatar_path',
                'user:id,name,avatar_path',
                'project:id,name,slug,color,key',
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_QUEUE_ROWS)
            ->get();

        return $rows->filter(
            static fn (AiToolRun $toolRun): bool => Gate::forUser($actor)->allows('approve', $toolRun),
        )->values();
    }

    public function pendingApprovalCount(): int
    {
        return $this->pendingApprovals->count();
    }

    /* ------------------------------------------------------------------ *
     * Card helpers, shared by the queue and the inline card
     * ------------------------------------------------------------------ */

    /**
     * A destructive call is the one place a click is not enough.
     */
    public function needsTypedConfirmation(AiToolRun $toolRun): bool
    {
        return ($toolRun->risk ?? AiToolRisk::Read) === AiToolRisk::Destructive;
    }

    /**
     * When this request stops licensing the call, whatever anybody decides afterwards.
     */
    public function approvalExpiresAt(AiToolRun $toolRun): ?Carbon
    {
        $created = $toolRun->created_at;

        return $created instanceof Carbon ? $created->copy()->addMinutes($this->approvalTtlMinutes()) : null;
    }

    public function approvalExpired(AiToolRun $toolRun): bool
    {
        $expiry = $this->approvalExpiresAt($toolRun);

        return $expiry instanceof Carbon && $expiry->isPast();
    }

    /**
     * The arguments exactly as they will execute.
     *
     * Rendered as a list of key/value rows rather than as JSON because an approval is a
     * decision a person makes, and nobody should have to parse braces to make it. The
     * values themselves are untouched.
     *
     * @return list<array{key: string, value: string, multiline: bool}>
     */
    public function approvalArguments(AiToolRun $toolRun): array
    {
        $arguments = is_array($toolRun->arguments) ? $toolRun->arguments : [];
        $rows = [];

        foreach ($arguments as $key => $value) {
            $rendered = match (true) {
                $value === null => '—',
                is_bool($value) => $value ? __('yes') : __('no'),
                is_int($value), is_float($value) => (string) $value,
                is_string($value) => $value,
                default => json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '—',
            };

            $rows[] = [
                'key' => (string) $key,
                'value' => $rendered,
                'multiline' => mb_strlen($rendered) > 80 || str_contains($rendered, "\n"),
            ];
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * The row, still pending, inside this tenant.
     *
     * The workspace scope does the tenancy work — an id from another workspace does not
     * resolve at all — and the status check means a double click, or two approvers racing,
     * decides once. {@see ApprovalService} makes that atomic as well; this only avoids
     * showing a second person an error for a race they did not cause.
     */
    private function pendingToolRun(int $toolRunId): ?AiToolRun
    {
        $toolRun = AiToolRun::query()->whereKey($toolRunId)->first();

        if (! $toolRun instanceof AiToolRun) {
            return null;
        }

        if ($toolRun->status !== ToolRunStatus::PendingApproval) {
            $this->dispatch('planvio-notify', type: 'info', message: __('That request has already been decided.'));
            $this->afterApprovalDecision($toolRun);

            return null;
        }

        return $toolRun;
    }

    private function approvalTtlMinutes(): int
    {
        $configured = config('ai.approvals.approval_ttl_minutes');

        return is_int($configured) && $configured > 0 ? $configured : self::DEFAULT_TTL_MINUTES;
    }

    /**
     * Hook for the host surface to refresh whatever it is showing. A no-op by default so a
     * component that only lists approvals does not have to care.
     */
    protected function afterApprovalDecision(AiToolRun $toolRun): void
    {
        unset($this->pendingApprovals);
    }
}
