<?php

declare(strict_types=1);

namespace App\Ai\Agent;

use App\Ai\Contracts\ToolCall as ProviderToolCall;
use App\Ai\Support\Redactor;

/**
 * One tool invocation the model asked for, before anything has been decided about it.
 *
 * A ToolCall is *a request*, not permission to do anything. The name is a bare string that
 * has not yet been through the registry, and the arguments are unvalidated model output.
 * Nothing here resolves a class, touches the database or trusts a value — that is exactly
 * why the type exists: it gives the runner something to log, de-duplicate and reject before
 * any of those steps happen (ARCHITECTURE.md §7.1).
 *
 * The idempotency key is the interesting part. A retrying agent that repeats a mutation with
 * identical arguments inside one run must not create a second record, so every mutating call
 * is keyed by `sha1(run_id | tool | canonical_args)` and the runner returns the earlier
 * result on a repeat (§7.5). "Canonical" means recursively key-sorted JSON: the same
 * arguments in a different key order are the same call, because the model's key order is not
 * stable and a duplicate that slipped through on ordering would be a duplicate record.
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments raw, unvalidated model output
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public int $sequence = 0,
    ) {}

    /**
     * @param array<string, mixed> $arguments
     */
    public static function make(
        string $name,
        array $arguments = [],
        ?string $id = null,
        int $sequence = 0,
    ): self {
        return new self(
            id: $id ?? self::syntheticId($name, $sequence),
            name: $name,
            arguments: $arguments,
            sequence: $sequence,
        );
    }

    /**
     * Adopt a call the provider normalised.
     *
     * {@see ProviderToolCall} is the wire shape — whatever the model emitted, decoded and
     * given a uniform form across providers. This is the run shape: the same call once it has
     * a position in the loop, an idempotency key and a redacted form fit for the audit trail.
     * The conversion is deliberately one-way and explicit, so it is always clear which side of
     * the provider boundary a call is on.
     *
     * A malformed call is carried through with empty arguments rather than dropped: the
     * registry and schema validation are what refuse it, and refusing it here would leave no
     * `ai_tool_runs` row explaining why nothing happened.
     */
    public static function fromProviderCall(ProviderToolCall $call, int $sequence = 0): self
    {
        return new self(
            id: $call->id === '' ? self::syntheticId($call->name, $sequence) : $call->id,
            name: $call->name,
            arguments: $call->arguments,
            sequence: $sequence,
        );
    }

    public function withSequence(int $sequence): self
    {
        return new self($this->id, $this->name, $this->arguments, $sequence);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function withArguments(array $arguments): self
    {
        return new self($this->id, $this->name, $arguments, $this->sequence);
    }

    public function argument(string $key, mixed $default = null): mixed
    {
        return $this->arguments[$key] ?? $default;
    }

    public function hasArgument(string $key): bool
    {
        return array_key_exists($key, $this->arguments);
    }

    /* ------------------------------------------------------------------ *
     * Idempotency
     * ------------------------------------------------------------------ */

    /**
     * `sha1(run_id | tool | canonical_args)` — 40 characters, inside the 80-character
     * `ai_tool_runs.idempotency_key` column, unique per run.
     */
    public function idempotencyKey(int|string $runId): string
    {
        return sha1($runId.'|'.$this->name.'|'.$this->canonicalArguments());
    }

    /**
     * Arguments as deterministic JSON: keys sorted at every depth, so two calls that differ
     * only in key order produce the same string.
     */
    public function canonicalArguments(): string
    {
        $encoded = json_encode(
            self::canonicalise($this->arguments),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );

        // json_encode only fails on malformed UTF-8 or recursion, neither of which may take
        // an idempotency key out of play: a call that cannot be canonicalised is treated as
        // unique, which is the safe direction (it executes at most once and is never
        // mistaken for an earlier call).
        return $encoded === false ? '' : $encoded;
    }

    /* ------------------------------------------------------------------ *
     * Audit
     * ------------------------------------------------------------------ */

    /**
     * The shape written to `ai_tool_runs.arguments` and to logs, with every credential-shaped
     * value stripped (CLAUDE.md rule 4, AI_SECURITY.md "What is logged").
     *
     * @return array{id: string, name: string, sequence: int, arguments: array<array-key, mixed>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sequence' => $this->sequence,
            'arguments' => $this->redactedArguments(),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function redactedArguments(?Redactor $redactor = null): array
    {
        return ($redactor ?? new Redactor)->redactArray($this->arguments);
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * Providers that do not supply a call id still need one to correlate the tool result
     * back to the request, so a stable synthetic id is derived from the position in the run.
     */
    private static function syntheticId(string $name, int $sequence): string
    {
        return 'call_'.$sequence.'_'.substr(sha1($name.'|'.$sequence), 0, 16);
    }

    private static function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];

        foreach ($value as $key => $item) {
            $canonical[$key] = self::canonicalise($item);
        }

        // Lists keep their order — it is meaningful. Only associative keys are sorted.
        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
