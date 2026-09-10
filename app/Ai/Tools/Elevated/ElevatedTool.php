<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\ToolRunStatus;
use App\Models\AiToolRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The one thing the high-risk and destructive tools do that no other tool does: refuse to run
 * until a person has said yes.
 *
 * Everything else they need — schema validation, idempotent replay, workspace binding, the
 * `App\Actions\*` call, the failure translation — is {@see MutatesThroughActions}, shared with
 * every mutating tool. What is added here is the half that only applies to the tools at the
 * top of the risk scale:
 *
 * **The unwaivable approval gate.** `delete_project`, `delete_task`, `remove_workspace_member`
 * and `archive_project` require a human approval in every mode, and no `AiPolicy` may waive it
 * (AI_SECURITY.md, "Approval gating"). The agent runner enforces that in its own gate before a
 * tool is ever called. {@see approvalGate()} enforces it again, from inside the tool, against
 * the `ai_tool_runs` row the approval was recorded on. The duplication is the point: a bug in
 * the runner, a refactor, a new execution path, or a policy stack that somehow answers "no
 * approval needed" still cannot delete a project, because the tool itself will not proceed
 * without an approval it can see — granted by a named human, inside this run, within the
 * configured TTL.
 *
 * **A stated blast radius.** {@see ReportsConsequences::consequences()} returns counted facts
 * for the approval card, so the person approving is judging the real scope rather than a
 * sentence the model wrote about it.
 *
 * None of this is a second authority. Every subject is still resolved inside the bound
 * workspace, still asserted with `AgentContext::assertInWorkspace()`, and still authorised
 * with `AgentContext::can()` — which is `Gate::forUser($actingUser)` and nothing else.
 */
abstract class ElevatedTool implements ReportsConsequences
{
    use MutatesThroughActions;

    /**
     * The tools no policy may waive, held in code as well as in
     * `config('ai.approvals.always_require_approval')`.
     *
     * The config list is what the runner and the admin UI read, and an administrator can edit
     * it. This constant is what makes the guarantee structural: the two are unioned, so a name
     * disappearing from config lowers nothing, and the only way to let one of these execute
     * unattended is to edit this file.
     *
     * @var list<string>
     */
    public const UNWAIVABLE = [
        'delete_project',
        'delete_task',
        'remove_workspace_member',
        'archive_project',
    ];

    /**
     * Whether $tool may never execute without a recorded human approval.
     */
    final public static function requiresUnwaivableApproval(string $tool): bool
    {
        if (in_array($tool, self::UNWAIVABLE, true)) {
            return true;
        }

        $configured = config('ai.approvals.always_require_approval');

        return is_array($configured) && in_array($tool, $configured, true);
    }

    /**
     * Null when the call may proceed; the refusal to return unchanged when it may not.
     *
     * A matching approval is one recorded against *this run* and *this tool*, marked approved
     * by a named user, no older than `config('ai.approvals.approval_ttl_minutes')`, and pinned
     * either to the exact arguments — via the same `idempotencyKey()` the runner writes to
     * `ai_tool_runs` — or to the exact record. Both branches pin the run, the tool and the
     * thing being acted on, so an approval granted for one call cannot license another.
     *
     * $args must be the *raw* arguments `execute()` received, not the coerced ones, because
     * that is what the key was computed from when the pending row was written.
     *
     * @param array<string, mixed> $args
     */
    final protected function approvalGate(array $args, AgentContext $ctx, ?Model $subject = null): ?ToolResult
    {
        $tool = $this->name();

        if (! self::requiresUnwaivableApproval($tool)) {
            return null;
        }

        $key = $this->idempotencyKey($args, $ctx);
        $cutoff = Carbon::now()->subMinutes(self::approvalTtlMinutes());

        $approved = $ctx->bindWorkspace(static fn (): bool => AiToolRun::query()
            ->forWorkspace($ctx->workspaceId())
            ->forRun($ctx->runId())
            ->forTool($tool)
            ->withStatus(ToolRunStatus::Approved)
            ->whereNotNull('approved_by')
            ->whereNotNull('approved_at')
            ->where('approved_at', '>=', $cutoff)
            ->where(static function (Builder $query) use ($key, $subject): void {
                $query->where('idempotency_key', $key);

                if ($subject !== null) {
                    $query->orWhere(static fn (Builder $pinned): Builder => $pinned
                        ->where('subject_type', $subject->getMorphClass())
                        ->where('subject_id', $subject->getKey()));
                }
            })
            ->exists());

        if ($approved) {
            return null;
        }

        return ToolResult::failed(
            __(':tool always needs a person to approve it before it runs, whatever the mode, so nothing has been changed. Describe exactly what you intend to do — including what it would affect — and ask the user to approve it.', [
                'tool' => $tool,
            ]),
            'approval_required',
            ['tool' => $tool, 'approval_required' => true],
        );
    }

    private static function approvalTtlMinutes(): int
    {
        $configured = config('ai.approvals.approval_ttl_minutes');

        return is_int($configured) && $configured > 0 ? $configured : 1440;
    }
}
