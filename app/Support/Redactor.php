<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Strips credential-bearing values out of any structure on its way to durable storage.
 *
 * Every audit surface in Planvio — `activities.properties`, `ai_tool_runs.arguments`,
 * `audit_logs.properties` — is rendered back to a human, so a secret that reaches one of
 * them has effectively been published. The key list is `config('ai.logging.redact_keys')`
 * (ARCHITECTURE.md §7.5, CLAUDE.md rule 4); this class is the single implementation of it.
 *
 * Matching is structural rather than substring-based. A key is split into word segments
 * ("openai_api_key" -> [openai, api, key], "accessToken" -> [access, token]) and redacted
 * when a configured key's segments appear as a contiguous run. Plain substring matching
 * would redact `tokens_in` and `tokens_out` — real, non-secret token *counters* the AI
 * usage rollup depends on — because they contain "token".
 */
final class Redactor
{
    public const REDACTED = '[redacted]';

    /**
     * Deeper nesting than this cannot come from a tool argument or an activity payload;
     * the cap exists so a self-referential array cannot spin the walker.
     */
    private const MAX_DEPTH = 12;

    /**
     * Configured keys, pre-split into lowercase segments.
     *
     * @var list<non-empty-list<string>>
     */
    private array $patterns;

    /**
     * @param list<string>|null $keys overrides `config('ai.logging.redact_keys')`
     */
    public function __construct(?array $keys = null)
    {
        $keys ??= self::configuredKeys();

        $patterns = [];

        foreach ($keys as $key) {
            $segments = self::segments((string) $key);

            if ($segments !== []) {
                $patterns[] = $segments;
            }
        }

        $this->patterns = $patterns;
    }

    /**
     * Redact every sensitive value in the structure, preserving its shape.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function redact(array $data): array
    {
        return $this->walk($data, 0);
    }

    /**
     * Whether a value stored under this key would be redacted.
     */
    public function isSensitive(string $key): bool
    {
        $segments = self::segments($key);

        if ($segments === []) {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            if (self::containsRun($segments, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The keys this instance redacts, as originally configured.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(
            static fn (array $segments): string => implode('_', $segments),
            $this->patterns,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    private function walk(array $data, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return [];
        }

        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = is_array($value) ? $this->walk($value, $depth + 1) : $value;
        }

        return $result;
    }

    /**
     * Lowercase word segments of an identifier, splitting on separators and camel humps.
     *
     * @return list<string>
     */
    private static function segments(string $key): array
    {
        $spaced = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $parts = preg_split('/[^A-Za-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false) {
            return [];
        }

        return array_values(array_map(
            static fn (string $part): string => mb_strtolower($part),
            $parts,
        ));
    }

    /**
     * Whether $needle appears in $haystack as a contiguous run of whole segments.
     *
     * @param list<string> $haystack
     * @param non-empty-list<string> $needle
     */
    private static function containsRun(array $haystack, array $needle): bool
    {
        $span = count($needle);
        $limit = count($haystack) - $span;

        for ($offset = 0; $offset <= $limit; $offset++) {
            if (array_slice($haystack, $offset, $span) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function configuredKeys(): array
    {
        $configured = config('ai.logging.redact_keys');

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => is_scalar($key) ? (string) $key : '', $configured),
            static fn (string $key): bool => $key !== '',
        ));
    }
}
