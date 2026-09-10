<?php

declare(strict_types=1);

namespace App\Ai\Verification;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Enums\ToolRunStatus;
use App\Models\AiToolRun;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-reads what a mutating tool said it changed, and records whether the claim held.
 *
 * ## Why this exists
 *
 * `resources/ai/system.md` tells the model: "A tool returning success is evidence the call
 * was accepted, not proof the world is how you think it is." That sentence is only honest if
 * something actually checks. This class is that something. Without it the instruction is an
 * aspiration the model is asked to honour by introspection, which is exactly the kind of
 * control AI_SECURITY.md declines to rely on.
 *
 * ## What it checks, and why only these things
 *
 * Three questions, each of which has a definite answer and a definite failure:
 *
 * 1. **Is the record still there?** The subject is re-read through a fresh, workspace-scoped
 *    query — not from the in-memory instance the tool handed back, which would only prove
 *    that PHP remembers what it just built. A subject that cannot be read back means the
 *    tool reported a success the database does not support.
 * 2. **Is it in this workspace?** A subject whose `workspace_id` is not the bound one is a
 *    tenancy breach that got past the tool's own assertion, and it is logged as such
 *    (AI_SECURITY.md, "Workspace isolation").
 * 3. **Do the claims match the columns?** Every scalar in `ToolResult::data` whose key is a
 *    real column on the re-read record is compared against the stored value. A tool that
 *    reports `priority: high` on a task the database says is `low` has misreported, and the
 *    model must not repeat it.
 *
 * The comparison is deliberately conservative, because a false alarm is worse than a missed
 * one here: it would turn a correct run into a `partial` one and teach the model to distrust
 * a boundary that is working. So a claim is compared only when the stored value is a column
 * (not an accessor, not a relation) and only when the two values are of comparable kinds —
 * `created_by: "Dana Manager"` against the integer column `created_by` is a difference of
 * vocabulary, not of fact, and is skipped rather than reported. Sanitised long-form columns
 * are skipped for the same reason: `HtmlSanitizer` legitimately rewrites what was submitted.
 *
 * ## Timing
 *
 * The runner calls this **after** the tool's transaction has committed. Verifying inside the
 * transaction would read the run's own uncommitted write, which proves nothing at all.
 */
final class ResultVerifier
{
    public const OUTCOME_VERIFIED = 'verified';

    public const OUTCOME_DELETED = 'deleted';

    public const OUTCOME_MISSING = 'missing';

    public const OUTCOME_FOREIGN = 'foreign_workspace';

    public const OUTCOME_MISMATCH = 'claim_mismatch';

    public const OUTCOME_NOT_APPLICABLE = 'not_applicable';

    /**
     * Columns whose stored form is legitimately not the string the tool reported: long-form
     * text goes through `HtmlSanitizer`, and excerpts are built for the model rather than read
     * from a column.
     *
     * @var list<string>
     */
    private const OPAQUE_KEYS = [
        'description',
        'body',
        'content',
        'excerpt',
        'html',
        'notes',
    ];

    /** At most this many mismatching field names are named in the note. */
    private const MAX_REPORTED_FIELDS = 5;

    /**
     * Verify $result and write the outcome onto $record.
     *
     * Returns the result the model should see: the original when the claim held, and a failed
     * result naming the discrepancy when it did not. A downgrade here is not cosmetic — the
     * runner counts it as a tool failure, so the run finishes `partial` rather than
     * `succeeded`, which is the honest answer when the record does not agree with the report.
     */
    public function verify(AiTool $tool, ToolResult $result, AgentContext $ctx, AiToolRun $record): ToolResult
    {
        if (! $tool->isMutating() || ! $result->ok) {
            return $result;
        }

        $subject = $result->subject;

        if (! $subject instanceof Model) {
            // Bulk tools and notifications legitimately touch no single record. Saying so is
            // better than silently implying the claim was checked.
            $this->attach($record, self::OUTCOME_NOT_APPLICABLE, __('Not verified: the tool reported no single record to read back.'));

            return $result;
        }

        try {
            $fresh = $this->reread($subject, $ctx);
        } catch (Throwable $e) {
            // A verification that cannot run is not a verification that passed, but neither is
            // it evidence the write failed. The class name is safe to record; nothing else
            // from the throwable is (CLAUDE.md rule 4).
            $this->attach(
                $record,
                self::OUTCOME_NOT_APPLICABLE,
                __('Not verified: the record could not be read back (:reason).', ['reason' => class_basename($e)]),
            );

            return $result;
        }

        if ($fresh === null) {
            return $this->trashed($subject, $ctx) !== null
                ? $this->pass($result, $record, self::OUTCOME_DELETED, __('Verified: the record is no longer active.'))
                : $this->fail($result, $record, self::OUTCOME_MISSING, __('The tool reported success, but the record could not be read back afterwards. Treat it as not done.'));
        }

        if (! $this->inWorkspace($fresh, $ctx)) {
            Log::warning('ai.verification.foreign_subject', [
                'ai_run_id' => (int) $record->ai_run_id,
                'ai_tool_run_id' => (int) $record->getKey(),
                'tool' => (string) $record->tool,
                'subject_type' => $fresh->getMorphClass(),
                'workspace_id' => $ctx->workspaceId(),
            ]);

            return $this->fail(
                $result,
                $record,
                self::OUTCOME_FOREIGN,
                __('The record read back belongs to a different workspace. The change has not been accepted.'),
            );
        }

        $mismatches = $this->mismatches($fresh, $result->data);

        if ($mismatches !== []) {
            return $this->fail(
                $result,
                $record,
                self::OUTCOME_MISMATCH,
                __('The stored record does not match what was reported for: :fields. Re-read it before saying what it contains.', [
                    'fields' => implode(', ', array_slice($mismatches, 0, self::MAX_REPORTED_FIELDS)),
                ]),
            );
        }

        return $this->pass($result, $record, self::OUTCOME_VERIFIED, __('Verified: the record was read back and matches.'));
    }

    /* ------------------------------------------------------------------ *
     * Reading the record back
     * ------------------------------------------------------------------ */

    /**
     * A fresh read through the model's own query, with the workspace bound so the global
     * scope applies. The in-memory instance is used only for its class and key.
     */
    private function reread(Model $subject, AgentContext $ctx): ?Model
    {
        return $ctx->bindWorkspace(static function () use ($subject): ?Model {
            /** @var Builder<Model> $query */
            $query = $subject->newQuery();

            return $query->whereKey($subject->getKey())->first();
        });
    }

    /**
     * The same read, including soft-deleted rows — which is how a successful deletion is
     * distinguished from a record that was never written.
     */
    private function trashed(Model $subject, AgentContext $ctx): ?Model
    {
        if (! in_array(SoftDeletes::class, class_uses_recursive($subject), true)) {
            return null;
        }

        return $ctx->bindWorkspace(static function () use ($subject): ?Model {
            /** @var Builder<Model> $query */
            $query = $subject->newQuery()->withTrashed();

            return $query->whereKey($subject->getKey())->first();
        });
    }

    private function inWorkspace(Model $subject, AgentContext $ctx): bool
    {
        if ($subject instanceof Workspace) {
            return (int) $subject->getKey() === $ctx->workspaceId();
        }

        $workspaceId = $subject->getAttribute('workspace_id');

        // A record with no tenant column of its own — a checklist item, a project membership —
        // carries its tenancy on its parent, which the tool asserted before it wrote. There is
        // nothing to re-check here, so this is not treated as a breach.
        if ($workspaceId === null) {
            return true;
        }

        return is_numeric($workspaceId) && (int) $workspaceId === $ctx->workspaceId();
    }

    /* ------------------------------------------------------------------ *
     * Comparing the claim against the columns
     * ------------------------------------------------------------------ */

    /**
     * Field names the tool reported differently from the way the database stores them.
     *
     * @param array<array-key, mixed> $claims
     * @return list<string>
     */
    private function mismatches(Model $fresh, array $claims): array
    {
        // Raw attributes, not accessors: an accessor could compute its answer from the very
        // value being checked, which would make the check agree with itself.
        $stored = $fresh->getAttributes();
        $mismatches = [];

        foreach ($claims as $key => $claimed) {
            if (! is_string($key) || in_array($key, self::OPAQUE_KEYS, true)) {
                continue;
            }

            if (! array_key_exists($key, $stored)) {
                continue;
            }

            if (! self::comparable($claimed, $stored[$key])) {
                continue;
            }

            if (! self::equivalent($claimed, $stored[$key])) {
                $mismatches[] = $key;
            }
        }

        return $mismatches;
    }

    /**
     * Whether the two values are of kinds it is meaningful to compare.
     *
     * The exclusions matter more than the inclusions. A string claim against an integer
     * column is a label for an id (`created_by: "Dana Manager"`), not a contradiction, and
     * structures are never compared because a JSON column and a decoded array differ in shape
     * without differing in content.
     */
    private static function comparable(mixed $claimed, mixed $stored): bool
    {
        if (is_array($claimed) || is_array($stored) || is_object($stored)) {
            return false;
        }

        if ($claimed === null) {
            return true;
        }

        if (is_bool($claimed)) {
            return is_bool($stored) || is_int($stored) || (is_string($stored) && in_array($stored, ['0', '1'], true));
        }

        if (is_int($claimed) || is_float($claimed)) {
            return $stored === null || is_numeric($stored);
        }

        return is_string($claimed) && ($stored === null || is_string($stored));
    }

    private static function equivalent(mixed $claimed, mixed $stored): bool
    {
        if ($claimed === null) {
            return $stored === null;
        }

        if ($stored === null) {
            return false;
        }

        if (is_bool($claimed)) {
            return $claimed === self::asBool($stored);
        }

        if (is_int($claimed) || is_float($claimed)) {
            return is_numeric($stored) && abs((float) $stored - (float) $claimed) < 0.000001;
        }

        $claimedText = trim((string) $claimed);
        $storedText = trim((string) $stored);

        if ($claimedText === $storedText) {
            return true;
        }

        // Tools clip long values for the model with a trailing ellipsis; the prefix is still
        // a truthful claim about the stored value.
        if (str_ends_with($claimedText, '…')) {
            return str_starts_with($storedText, mb_substr($claimedText, 0, -1));
        }

        // A date-only claim against a datetime column: "2026-09-30" describes
        // "2026-09-30 00:00:00" correctly.
        return mb_strlen($claimedText) === 10
            && preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $claimedText) === 1
            && str_starts_with($storedText, $claimedText);
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array($value, [0, '0', '', 'false'], true);
    }

    /* ------------------------------------------------------------------ *
     * Attaching the outcome
     * ------------------------------------------------------------------ */

    private function pass(ToolResult $result, AiToolRun $record, string $outcome, string $note): ToolResult
    {
        $this->attach($record, $outcome, $note);

        return new ToolResult(
            ok: true,
            data: [...$result->data, 'verified' => true],
            summary: $result->summary,
            error: null,
            subject: $result->subject,
        );
    }

    private function fail(ToolResult $result, AiToolRun $record, string $outcome, string $note): ToolResult
    {
        $this->attach($record, $outcome, $note, failed: true);

        return new ToolResult(
            ok: false,
            data: [...$result->data, 'verified' => false, 'verification' => $outcome],
            summary: $result->summary.' '.$note,
            error: 'verification_failed:'.$outcome,
            subject: $result->subject,
        );
    }

    /**
     * The audit half: the outcome lands on the tool run itself, so the log a human reads
     * shows whether the claim was checked without having to re-derive it.
     */
    private function attach(AiToolRun $record, string $outcome, string $note, bool $failed = false): void
    {
        $summary = trim((string) $record->result_summary);
        $line = __('Verification (:outcome): :note', ['outcome' => $outcome, 'note' => $note]);

        $record->result_summary = self::clip(
            $summary === '' ? $line : $summary."\n".$line,
            self::maxSummaryCharacters(),
        );

        if ($failed) {
            $record->status = ToolRunStatus::Failed;
            $record->error = self::clip('verification_failed:'.$outcome, 191);
        }

        $record->save();
    }

    private static function clip(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, max(1, $limit - 1)).'…';
    }

    private static function maxSummaryCharacters(): int
    {
        $configured = config('ai.logging.max_stored_summary_chars');

        return is_int($configured) && $configured > 0 ? $configured : 1000;
    }
}
