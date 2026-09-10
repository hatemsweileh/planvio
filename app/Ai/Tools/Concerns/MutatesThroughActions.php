<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * The fixed order every mutating tool runs in, written once.
 *
 * ARCHITECTURE.md section 7.1 fixes the sequence and AI_SECURITY.md explains why each step
 * exists. Restating it in twenty `execute()` bodies would mean twenty chances to drop one,
 * and the step most easily dropped — the workspace assertion — is the one whose absence is a
 * silent cross-tenant write rather than a visible bug. So the shared half lives here and each
 * tool supplies only the half that is genuinely its own:
 *
 *   1. **Schema validation** ({@see ValidatesArguments}). Extra or malformed arguments are
 *      rejected, never ignored.
 *   2. **Idempotency replay** ({@see ComputesIdempotency}). An identical call already made in
 *      this run returns the earlier result instead of executing again.
 *   3. **Workspace binding.** The tool body runs inside `CurrentWorkspace::runFor()`, so the
 *      global scope is active for every query it makes — belt to the braces of the explicit
 *      `where workspace_id` each resolver adds.
 *   4. **The tool's own body**: resolve the subject in the workspace, assert it, ask the Gate
 *      as the acting user, then call one `App\Actions\*` class.
 *   5. **Failure translation.** A broken domain invariant becomes a factual `ToolResult` the
 *      model must report. Anything else becomes a result that names nothing.
 *
 * ## Why nothing here retries
 *
 * A tool that catches a refusal and tries a different route is exactly the thing
 * AI_SECURITY.md says does not exist. There is no branch below that re-runs an Action, drops
 * an argument, or substitutes a different subject after a failure. A denial ends the call.
 *
 * ## Why exception messages are not passed through wholesale
 *
 * `DomainException` messages are written for the person who triggered the action and are
 * translated at the throw site, so they are safe and useful to hand back. Everything else —
 * a driver error, a transport failure — can carry a DSN, a URL with credentials in it or a
 * file path, and CLAUDE.md rule 4 admits no exceptions. Those become a fixed sentence plus
 * the exception's class name, which is enough to debug from `ai_tool_runs.error` and cannot
 * contain a secret.
 */
trait MutatesThroughActions
{
    use ComputesIdempotency;
    use ResolvesDates;
    use ResolvesSubjects;
    use ValidatesArguments;

    /**
     * Every tool using this trait changes state. That is what makes it unavailable in
     * assistant mode, subject to the approval gate, and required to carry an idempotency key.
     */
    public function isMutating(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $args
     * @param Closure(Arguments, AgentContext): ToolResult $act the tool's own body
     */
    protected function handle(array $args, AgentContext $ctx, Closure $act): ToolResult
    {
        [$errors, $values] = $this->checkSchema($args, $this->parameters());

        if ($errors !== []) {
            return ToolResult::failed(
                __('The arguments for :tool were rejected: :errors', [
                    'tool' => $this->name(),
                    'errors' => implode(' ', array_slice($errors, 0, 5)),
                ]),
                'invalid_arguments',
                ['errors' => array_slice($errors, 0, 5)],
            );
        }

        // Replay applies to writes only. A tool that changes nothing should answer freshly
        // every time it is asked — the model re-reads a record precisely because something
        // else in the run may have moved it, and handing back a cached answer there would
        // defeat the "verify what you changed" step the system prompt insists on.
        $mutating = $this->isMutating();
        $key = $mutating ? $this->idempotencyKey($args, $ctx) : '';

        if ($mutating) {
            $replay = $this->replayOf($key, $ctx);

            if ($replay instanceof ToolResult) {
                return $replay;
            }
        }

        $input = new Arguments($values);

        try {
            $result = $ctx->bindWorkspace(static fn (): ToolResult => $act($input, $ctx));
        } catch (DomainException $e) {
            // A domain invariant said no: a cycle, a non-member assignee, a status on
            // another project's board. The model must report it, not route around it.
            return ToolResult::failed($e->getMessage(), 'domain_rule_violated');
        } catch (Throwable $e) {
            return ToolResult::failed(
                __(':tool could not be completed because of an internal error. Nothing was changed.', [
                    'tool' => $this->name(),
                ]),
                'execution_failed:'.class_basename($e),
            );
        }

        return $mutating ? $this->rememberResult($key, $ctx, $result) : $result;
    }

    /**
     * The single answer for "no such record here".
     *
     * A record in another workspace, a soft-deleted one and one that never existed all end up
     * here, worded identically. Telling the three apart would turn the tool into an oracle a
     * model could enumerate ids against (AI_SECURITY.md, "Workspace isolation").
     */
    protected function notFound(string $label, int|string $id): ToolResult
    {
        return ToolResult::failed(
            __('No :label with id :id exists in this workspace.', ['label' => $label, 'id' => (string) $id]),
            'not_found',
        );
    }

    /**
     * The tool needs a project and neither the arguments nor the run's context named one.
     */
    protected function projectRequired(): ToolResult
    {
        return ToolResult::failed(
            __('Which project? Give project_id — this run is not focused on one.'),
            'project_required',
        );
    }

    /**
     * A refusal from the Gate, worded so the model reports the boundary rather than probing
     * it. The acting user's name is in it because the model is acting *for* somebody, and
     * "you cannot" is confusing when the "you" is a person the model never met.
     */
    protected function denied(AgentContext $ctx, string $what): ToolResult
    {
        return ToolResult::denied(__(':user does not have permission to :what.', [
            'user' => $ctx->user->name,
            'what' => $what,
        ]));
    }

    /**
     * Cut a string to $limit characters, saying so rather than trailing off silently: a model
     * shown a truncated list without a marker treats it as complete.
     */
    protected static function clip(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, max(1, $limit - 1)).'…';
    }

    /**
     * The ceiling from `config('ai.limits.max_tool_result_chars')`, or a fraction of it.
     */
    protected static function resultBudget(float $share = 1.0): int
    {
        $configured = config('ai.limits.max_tool_result_chars');
        $limit = is_int($configured) && $configured > 0 ? $configured : 6000;

        return max(80, (int) floor($limit * $share));
    }

    /**
     * The compact identity of a record, for a `ToolResult`'s data.
     *
     * @return array{id: int, type: string}
     */
    protected static function reference(Model $model): array
    {
        return [
            'id' => (int) $model->getKey(),
            'type' => $model->getMorphClass(),
        ];
    }
}
