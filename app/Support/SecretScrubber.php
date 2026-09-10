<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shape-based secret removal for free text.
 *
 * {@see Redactor} decides by key name, which works for structured data we assembled
 * ourselves. It cannot help with a blob of text that arrived from somewhere else — a
 * provider's error body, a webhook endpoint's response, an exception message quoting a
 * URL — where a credential appears in prose with no key attached to it.
 *
 * This class is deliberately outside the AI namespace. It was written there first, but a
 * webhook delivery job has the same problem and must not import from App\Ai to solve it.
 */
final class SecretScrubber
{
    public const REDACTED = Redactor::REDACTED;

    /**
     * Patterns anchored on a recognisable prefix or structure rather than on entropy alone,
     * because a regex cannot cheaply express "mixed case and digits". Entropy is checked
     * separately in {@see self::looksLikeSecretBlob()}.
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

    public function scrub(string $value): string
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

    public function containsSecret(string $value): bool
    {
        return $this->scrub($value) !== $value;
    }

    /**
     * Truncate after scrubbing, never before: cutting first can split a credential so that
     * neither half matches a pattern, leaving a recognisable prefix in the record.
     */
    public function scrubAndTruncate(string $value, int $maxChars): string
    {
        $value = $this->scrub($value);

        if ($maxChars <= 0 || mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return mb_substr($value, 0, $maxChars).'... [truncated]';
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
}
