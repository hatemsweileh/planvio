<?php

declare(strict_types=1);

namespace App\Ai\Support;

use App\Support\Redactor as KeyRedactor;

/**
 * The AI layer's redactor: everything {@see KeyRedactor} does, plus the two things that only
 * matter once provider traffic is involved.
 *
 * {@see KeyRedactor} decides by KEY — `api_key`, `token`, `authorization` and friends from
 * `config('ai.logging.redact_keys')`. That is necessary and not sufficient here, because the
 * dangerous strings in this layer arrive under innocuous names: a 401 body echoing the
 * rejected credential, a stack message quoting a URL with a token in it, a tool argument a
 * user pasted a key into. So this class also decides by SHAPE, scrubbing values that look
 * like credentials wherever they appear, and truncates to
 * `config('ai.logging.max_stored_argument_chars')` so one oversized value cannot dominate an
 * audit row.
 *
 * It is deliberately more eager than precise. Redacting a git SHA or a long base64 blob in an
 * audit record costs a little context; publishing an API key costs the deployment
 * (CLAUDE.md rule 4).
 */
final class Redactor
{
    public const REDACTED = KeyRedactor::REDACTED;

    /**
     * Deeper than any tool argument or provider payload legitimately nests; the cap stops a
     * self-referential structure spinning the walker.
     */
    private const MAX_DEPTH = 12;

    /**
     * Shapes that are credentials wherever they appear.
     *
     * Anchored on vendor prefixes where one exists, because those are unambiguous, and on
     * entropy where one does not. Entropy alone is checked in
     * {@see self::looksLikeSecretBlob()} rather than in a pattern, since a regex cannot
     * cheaply express "mixed case and digits".
     *
     * @var list<string>
     */
    private const SECRET_PATTERNS = [
        // OpenAI, Anthropic and the many endpoints that copied the prefix.
        '#\bsk-(?:[A-Za-z0-9]+-)*[A-Za-z0-9_-]{16,}#i',
        // GitHub personal access and app tokens.
        '#\b(?:ghp|gho|ghu|ghs|ghr|github_pat)_[A-Za-z0-9_]{16,}#',
        // Slack.
        '#\bxox[abposr]-[A-Za-z0-9-]{10,}#i',
        // Google API keys.
        '#\bAIza[0-9A-Za-z_-]{20,}#',
        // AWS access key ids.
        '#\b(?:AKIA|ASIA|AGPA|AIPA|ANPA|AROA)[0-9A-Z]{12,}#',
        // Anything presented as a bearer credential, whatever its shape.
        '#\bBearer\s+[A-Za-z0-9._~+/=-]{12,}#i',
        // JSON web tokens.
        '#\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_.-]*#',
        // Credentials embedded in a URL, e.g. https://user:secret@host.
        '#://[^/\s:@]+:[^/\s@]+@#',
        // Long hexadecimal: session ids, HMAC secrets, hashed credentials.
        '#\b[0-9a-f]{32,}\b#i',
    ];

    /**
     * A run of base64/base64url characters long enough that prose cannot produce it by
     * accident. Whether it is actually redacted depends on the entropy check.
     */
    private const BLOB_PATTERN = '#\b[A-Za-z0-9+/=_-]{40,}\b#';

    public function __construct(
        private readonly KeyRedactor $keys = new KeyRedactor,
        private readonly ?int $maxChars = null,
    ) {}

    /**
     * Redact a value, preserving its type: an array in, an array out; a string in, a string
     * out. Anything else is returned untouched.
     *
     * @param array<array-key, mixed>|string $value
     * @return array<array-key, mixed>|string
     */
    public function redact(array|string $value): array|string
    {
        return is_string($value)
            ? $this->redactString($value)
            : $this->redactArray($value);
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function redactArray(array $data): array
    {
        return $this->walk($data, 0);
    }

    /**
     * Scrub credential-shaped substrings, then truncate.
     *
     * Order matters: truncating first could slice a key in half and leave the readable
     * portion in place.
     */
    public function redactString(string $value): string
    {
        return $this->truncate($this->scrub($value));
    }

    /**
     * Whether a value stored under this key would be redacted outright.
     */
    public function isSensitiveKey(string $key): bool
    {
        return $this->keys->isSensitive($key);
    }

    /**
     * Whether the string contains something shaped like a credential.
     */
    public function containsSecret(string $value): bool
    {
        return $this->scrub($value) !== $value;
    }

    public function maxChars(): int
    {
        if ($this->maxChars !== null) {
            return max(1, $this->maxChars);
        }

        $configured = config('ai.logging.max_stored_argument_chars');

        return is_int($configured) && $configured > 0 ? $configured : 2000;
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
            if (is_string($key) && $this->keys->isSensitive($key)) {
                $result[$key] = self::REDACTED;

                continue;
            }

            $result[$key] = match (true) {
                is_array($value) => $this->walk($value, $depth + 1),
                is_string($value) => $this->redactString($value),
                default => $value,
            };
        }

        return $result;
    }

    private function scrub(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        foreach (self::SECRET_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTED, $value);

            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        $replaced = preg_replace_callback(
            self::BLOB_PATTERN,
            static fn (array $match): string => self::looksLikeSecretBlob($match[0])
                ? self::REDACTED
                : $match[0],
            $value,
        );

        return is_string($replaced) ? $replaced : $value;
    }

    /**
     * A long unbroken run is only treated as a secret when it mixes cases and digits the way
     * encoded key material does. Without that check a base64-looking file name or a long
     * identifier in a task title would be destroyed for no benefit.
     */
    private static function looksLikeSecretBlob(string $candidate): bool
    {
        return preg_match('/[a-z]/', $candidate) === 1
            && preg_match('/[A-Z]/', $candidate) === 1
            && preg_match('/[0-9]/', $candidate) === 1;
    }

    private function truncate(string $value): string
    {
        $limit = $this->maxChars();

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'... [truncated]';
    }
}
