<?php

declare(strict_types=1);

namespace App\Ai\Agent;

use App\Ai\Support\Redactor;
use Illuminate\Database\Eloquent\Model;

/**
 * The outcome of one tool call, in the shape ARCHITECTURE.md §7.2 fixes:
 * `{ ok, data, summary, error, subject }`.
 *
 * `summary` is the load-bearing field. It is what the model reads back and what
 * `ai_tool_runs.result_summary` stores, so it has to be a compact statement of fact —
 * "Created task WEB-42 'Recover campaign timeline' in Marketing Campaign" — never an
 * apology, an instruction, or prose that invites the model to infer something that did not
 * happen. A tool that says it succeeded when it did not is the failure mode the system
 * prompt's honesty rules cannot recover from.
 *
 * A failure is still a ToolResult, not an exception: the model must see *why* it failed and
 * report it, and the run must stay auditable. `denied()` exists separately from `failed()`
 * because "you are not allowed to do that" is a normal, expected answer from the
 * authorization layer working correctly (AI_SECURITY.md, "Tool authorization") — the run
 * continues, the model reports it, and there is no retry-with-more-privilege path.
 *
 * `subject` is the record the call touched, carried so the runner can write
 * `ai_tool_runs.subject_type`/`subject_id` and attribute the activity. It is a live model,
 * not something to serialise: {@see forModel()} and {@see toArray()} reduce it to ids.
 */
final readonly class ToolResult
{
    /**
     * @param array<array-key, mixed> $data structured detail for the model and the audit trail
     */
    public function __construct(
        public bool $ok,
        public array $data,
        public string $summary,
        public ?string $error = null,
        public ?Model $subject = null,
    ) {}

    /* ------------------------------------------------------------------ *
     * Construction
     * ------------------------------------------------------------------ */

    /**
     * @param array<array-key, mixed> $data
     */
    public static function ok(string $summary, array $data = [], ?Model $subject = null): self
    {
        return new self(ok: true, data: $data, summary: $summary, error: null, subject: $subject);
    }

    /**
     * A tool that ran and could not do the job.
     *
     * $error carries the machine-readable reason; keep it short and free of anything a
     * provider or exception might have embedded — nothing here may ever contain a
     * credential, and it is written to `ai_tool_runs.error`.
     *
     * @param array<array-key, mixed> $data
     */
    public static function failed(string $summary, ?string $error = null, array $data = []): self
    {
        return new self(ok: false, data: $data, summary: $summary, error: $error ?? $summary);
    }

    /**
     * Authorization said no. Distinct from {@see failed()} so the audit trail can tell a
     * refusal apart from a fault, and so the model is told plainly that the boundary held.
     *
     * @param array<array-key, mixed> $data
     */
    public static function denied(string $reason, array $data = []): self
    {
        return new self(
            ok: false,
            data: $data,
            summary: $reason,
            error: 'permission_denied',
        );
    }

    /**
     * The tool was not run at all — an idempotent repeat, a skipped step, a run that hit a
     * limit before this call.
     *
     * @param array<array-key, mixed> $data
     */
    public static function skipped(string $reason, array $data = []): self
    {
        return new self(ok: true, data: $data, summary: $reason, error: null);
    }

    /* ------------------------------------------------------------------ *
     * Derived reads
     * ------------------------------------------------------------------ */

    /**
     * Named `hasFailed()` rather than `failed()` because {@see self::failed()} is the static
     * factory, and PHP will not carry both under one name.
     */
    public function hasFailed(): bool
    {
        return ! $this->ok;
    }

    public function wasDenied(): bool
    {
        return $this->error === 'permission_denied';
    }

    public function subjectType(): ?string
    {
        return $this->subject?->getMorphClass();
    }

    public function subjectId(): ?int
    {
        $key = $this->subject?->getKey();

        return is_numeric($key) ? (int) $key : null;
    }

    /**
     * A copy carrying the record that was touched. Tools build their result before they have
     * something to point at often enough that threading it through every factory is worse.
     */
    public function withSubject(?Model $subject): self
    {
        return new self($this->ok, $this->data, $this->summary, $this->error, $subject);
    }

    /* ------------------------------------------------------------------ *
     * Serialisation
     * ------------------------------------------------------------------ */

    /**
     * What the model is shown, wrapped by `PromptBuilder` as untrusted data.
     *
     * Redacted and bounded: `config('ai.limits.max_tool_result_chars')` stops one large
     * result from flooding the context window, and the truncation is announced rather than
     * silent so the model does not treat a cut-off list as complete.
     *
     * @return array{ok: bool, summary: string, data: array<array-key, mixed>, error?: string, truncated?: bool}
     */
    public function forModel(?Redactor $redactor = null): array
    {
        $redactor ??= new Redactor;

        // The summary and the error code are redacted alongside the data, not trusted because
        // Planvio wrote most of them. A `DomainException` message from a library, or a record
        // title somebody pasted a key into, arrives here as ordinary prose (CLAUDE.md rule 4).
        $payload = [
            'ok' => $this->ok,
            'summary' => $redactor->redactString($this->summary),
            'data' => $redactor->redactArray($this->data),
        ];

        if ($this->error !== null) {
            $payload['error'] = $redactor->redactString($this->error);
        }

        $limit = self::maxResultCharacters();
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded !== false && mb_strlen($encoded) > $limit) {
            // The detail is what overflows, never the verdict: drop `data` rather than
            // truncate it into malformed JSON the model would try to interpret.
            $payload['data'] = [];
            $payload['truncated'] = true;
        }

        return $payload;
    }

    /**
     * The audit shape for `ai_tool_runs`, redacted and length-bounded.
     *
     * @return array{
     *     ok: bool,
     *     summary: string,
     *     error: string|null,
     *     subject_type: string|null,
     *     subject_id: int|null,
     *     data: array<array-key, mixed>
     * }
     */
    public function toArray(?Redactor $redactor = null): array
    {
        $redactor ??= new Redactor;

        return [
            'ok' => $this->ok,
            'summary' => self::clip($redactor->redactString($this->summary), self::maxSummaryCharacters()),
            'error' => $this->error === null
                ? null
                : self::clip($redactor->redactString($this->error), self::maxSummaryCharacters()),
            'subject_type' => $this->subjectType(),
            'subject_id' => $this->subjectId(),
            'data' => $redactor->redactArray($this->data),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private static function clip(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit - 1).'…';
    }

    private static function maxSummaryCharacters(): int
    {
        $configured = config('ai.logging.max_stored_summary_chars');

        return is_int($configured) && $configured > 0 ? $configured : 1000;
    }

    private static function maxResultCharacters(): int
    {
        $configured = config('ai.limits.max_tool_result_chars');

        return is_int($configured) && $configured > 0 ? $configured : 6000;
    }
}
