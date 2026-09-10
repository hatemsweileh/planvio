<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\ToolRunStatus;
use App\Models\AiToolRun;

/**
 * `sha1(run_uuid | tool | canonical_args)`, and the replay that key buys
 * (ARCHITECTURE.md section 7.5).
 *
 * The failure this prevents is mundane and expensive: an agent calls `create_task`, the
 * provider times out reading the response, the loop retries the identical call, and the
 * board now has two "Recover campaign timeline" cards that a human has to notice and merge.
 * Nothing upstream can prevent it — the retry is indistinguishable from a genuine second
 * request — so the guard has to live where the arguments are.
 *
 * ## Two layers, because there are two ways a repeat arrives
 *
 * 1. **The ledger.** Within one process, the result of a successful call is remembered
 *    against its key and handed straight back. This is the common case: a model that repeats
 *    itself does so inside the same loop, milliseconds later, before anything has been
 *    persisted for it to find.
 * 2. **`ai_tool_runs`.** When the runner has already recorded a succeeded row under this
 *    key — a resumed run, a second job attempt, a queue retry after the worker died — the
 *    stored summary is replayed instead. `unique(ai_run_id, idempotency_key)` makes that row
 *    the authoritative record that the work was done once.
 *
 * Only successful calls are remembered. A refusal or a domain error changed nothing, so
 * repeating it costs a query and returns the same answer; treating those as replayable would
 * pin a transient failure in place for the rest of the run.
 *
 * ## Canonicalisation
 *
 * The key has to be stable across argument orderings a provider does not promise to preserve,
 * so object keys are sorted recursively before encoding. Values are not normalised — `"5"`
 * and `5` hash differently — because normalising them would mean deciding that a title of
 * `"5"` is the number five, and a hashing function is the wrong place to make that decision.
 */
trait ComputesIdempotency
{
    /**
     * Results already produced in this run, keyed by idempotency key.
     *
     * Scoped to one run: {@see rememberResult()} discards the whole ledger when the run uuid
     * changes, so a long-lived queue worker cannot accumulate the results of every run it has
     * ever processed. Static rather than instance state because the container may hand out a
     * fresh tool instance per call.
     *
     * @var array<string, ToolResult>
     */
    private static array $resultLedger = [];

    private static string $ledgerRunUuid = '';

    /**
     * The key the runner writes to `ai_tool_runs.idempotency_key`.
     *
     * Takes the raw arguments so the runner and the tool always agree: canonicalisation
     * happens here, once, rather than at two call sites that could drift.
     *
     * @param array<string, mixed> $args
     */
    public function idempotencyKey(array $args, AgentContext $ctx): string
    {
        return sha1($ctx->runUuid().'|'.$this->name().'|'.self::canonicalJson($args));
    }

    /**
     * The result of an identical earlier call in this run, or null if there was none.
     */
    protected function replayOf(string $key, AgentContext $ctx): ?ToolResult
    {
        if (self::$ledgerRunUuid === $ctx->runUuid() && isset(self::$resultLedger[$key])) {
            $prior = self::$resultLedger[$key];

            // The same answer, marked as an echo. Returning the original result unchanged
            // would leave the model unable to tell that its second call did nothing, and a
            // model that cannot see its own repeat will keep repeating.
            return new ToolResult(
                ok: $prior->ok,
                data: [...$prior->data, 'repeated' => true],
                summary: $prior->summary,
                error: null,
                subject: $prior->subject,
            );
        }

        $recorded = $ctx->bindWorkspace(static fn (): ?AiToolRun => AiToolRun::query()
            ->forWorkspace($ctx->workspaceId())
            ->where('ai_run_id', $ctx->runId())
            ->where('idempotency_key', $key)
            ->where('status', ToolRunStatus::Succeeded->value)
            ->latest('id')
            ->first());

        if (! $recorded instanceof AiToolRun) {
            return null;
        }

        $summary = is_string($recorded->result_summary) && trim($recorded->result_summary) !== ''
            ? $recorded->result_summary
            : __('This exact call already ran in this run.');

        return ToolResult::skipped($summary, [
            'repeated' => true,
            'ai_tool_run_id' => (int) $recorded->getKey(),
            'subject_type' => $recorded->subject_type,
            'subject_id' => $recorded->subject_id === null ? null : (int) $recorded->subject_id,
        ]);
    }

    /**
     * Remember a successful call so the next identical one replays instead of executing.
     */
    protected function rememberResult(string $key, AgentContext $ctx, ToolResult $result): ToolResult
    {
        if (self::$ledgerRunUuid !== $ctx->runUuid()) {
            self::$ledgerRunUuid = $ctx->runUuid();
            self::$resultLedger = [];
        }

        if ($result->ok) {
            self::$resultLedger[$key] = $result;
        }

        return $result;
    }

    /**
     * Deterministic JSON: object keys sorted at every depth, list order preserved.
     */
    private static function canonicalJson(mixed $value): string
    {
        $encoded = json_encode(
            self::canonicalise($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        // A payload PHP cannot encode (invalid UTF-8, a resource) still needs a stable key
        // rather than an exception: serialize() is deterministic and never reaches the model.
        return $encoded === false ? md5(serialize(self::canonicalise($value))) : $encoded;
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(static fn (mixed $item): mixed => self::canonicalise($item), $value);
        }

        ksort($value);

        $sorted = [];

        foreach ($value as $key => $item) {
            $sorted[$key] = self::canonicalise($item);
        }

        return $sorted;
    }
}
