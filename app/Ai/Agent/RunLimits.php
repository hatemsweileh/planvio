<?php

declare(strict_types=1);

namespace App\Ai\Agent;

use App\Models\AiSetting;

/**
 * The execution ceilings one agent run may not exceed (ARCHITECTURE.md §7.5).
 *
 * The whole point of this object is the direction of the clamp: `config('ai.limits')` is the
 * platform ceiling and a workspace may only ever *lower* it. A workspace row asking for 500
 * tool calls gets the configured 25, not 500 — otherwise a tenant could write its own budget
 * on shared hosting, which is the one thing the limits exist to prevent.
 *
 * Values are normalised on the way in rather than trusted: `max_tool_calls_per_run = 0` in
 * the database would otherwise mean "no run may ever take a step", and a negative ceiling
 * from a mistyped env var would mean the same. Anything unusable falls back to the ceiling,
 * and the ceiling itself has a floor of 1.
 *
 * `maxSameToolRepeats` and `maxContextTokens` have no per-workspace column: they are loop
 * and prompt-shape guards rather than budget settings, so they come from config alone.
 */
final readonly class RunLimits
{
    /** Steps the loop may take before it stops with `limit_reached`. */
    public int $maxToolCalls;

    /** Wall-clock seconds the run may consume. */
    public int $maxSeconds;

    /** Failed tool calls tolerated before the run stops thrashing. */
    public int $maxErrors;

    /** Consecutive-or-not repeats of one tool name inside a run. */
    public int $maxSameToolRepeats;

    /** Token budget the assembled context must fit inside. */
    public int $maxContextTokens;

    public function __construct(
        int $maxToolCalls,
        int $maxSeconds,
        int $maxErrors,
        int $maxSameToolRepeats,
        int $maxContextTokens,
    ) {
        // Assigned rather than promoted because every one of them is clamped: a limit that
        // can be constructed as 0 or negative is not a limit.
        $this->maxToolCalls = max(1, $maxToolCalls);
        $this->maxSeconds = max(1, $maxSeconds);
        $this->maxErrors = max(1, $maxErrors);
        $this->maxSameToolRepeats = max(1, $maxSameToolRepeats);
        $this->maxContextTokens = max(1, $maxContextTokens);
    }

    /* ------------------------------------------------------------------ *
     * Construction
     * ------------------------------------------------------------------ */

    /**
     * The limits governing a run under $settings: the workspace's own numbers, each capped
     * at the platform ceiling.
     */
    public static function fromSettings(AiSetting $settings): self
    {
        $ceilings = self::ceilings();

        return new self(
            self::narrow($settings->max_tool_calls_per_run, $ceilings->maxToolCalls),
            self::narrow($settings->max_run_seconds, $ceilings->maxSeconds),
            self::narrow($settings->error_threshold, $ceilings->maxErrors),
            $ceilings->maxSameToolRepeats,
            $ceilings->maxContextTokens,
        );
    }

    /**
     * The platform ceilings from `config('ai.limits')` — what nothing may exceed.
     */
    public static function ceilings(): self
    {
        return new self(
            self::configured('max_tool_calls_per_run', 25),
            self::configured('max_run_seconds', 180),
            self::configured('max_errors_per_run', 3),
            self::configured('max_same_tool_repeats', 5),
            self::configured('max_context_tokens', 24000),
        );
    }

    /**
     * A copy with a smaller context budget. Narrowing only: a caller cannot widen the
     * prompt budget by handing back a bigger number.
     */
    public function withContextTokens(int $tokens): self
    {
        return new self(
            $this->maxToolCalls,
            $this->maxSeconds,
            $this->maxErrors,
            $this->maxSameToolRepeats,
            min($this->maxContextTokens, max(1, $tokens)),
        );
    }

    /* ------------------------------------------------------------------ *
     * Predicates — phrased as "has this run used them up?"
     * ------------------------------------------------------------------ */

    public function toolCallsExhausted(int $used): bool
    {
        return $used >= $this->maxToolCalls;
    }

    public function errorsExhausted(int $errors): bool
    {
        return $errors >= $this->maxErrors;
    }

    public function timeExhausted(float $elapsedSeconds): bool
    {
        return $elapsedSeconds >= (float) $this->maxSeconds;
    }

    public function repeatsExhausted(int $repeats): bool
    {
        return $repeats >= $this->maxSameToolRepeats;
    }

    public function contextExhausted(int $tokens): bool
    {
        return $tokens >= $this->maxContextTokens;
    }

    /**
     * @return array{
     *     max_tool_calls: int,
     *     max_seconds: int,
     *     max_errors: int,
     *     max_same_tool_repeats: int,
     *     max_context_tokens: int
     * }
     */
    public function toArray(): array
    {
        return [
            'max_tool_calls' => $this->maxToolCalls,
            'max_seconds' => $this->maxSeconds,
            'max_errors' => $this->maxErrors,
            'max_same_tool_repeats' => $this->maxSameToolRepeats,
            'max_context_tokens' => $this->maxContextTokens,
        ];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * A workspace value capped at the ceiling. Null, zero and negatives mean "unset", which
     * resolves to the ceiling rather than to nothing.
     */
    private static function narrow(?int $requested, int $ceiling): int
    {
        if ($requested === null || $requested < 1) {
            return $ceiling;
        }

        return min($requested, $ceiling);
    }

    private static function configured(string $key, int $default): int
    {
        $value = config('ai.limits.'.$key);

        if (is_int($value)) {
            return max(1, $value);
        }

        if (is_string($value) && ctype_digit($value)) {
            return max(1, (int) $value);
        }

        return $default;
    }
}
