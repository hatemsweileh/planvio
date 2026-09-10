<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Support\Redactor;
use App\Exceptions\DomainException;

/**
 * Every failure the transport layer can produce, in one credential-free shape.
 *
 * Three rules govern this class, and they are the reason it exists at all rather than the
 * providers simply letting Guzzle or Laravel exceptions escape:
 *
 * 1. **No previous exception is ever chained.** A chained throwable keeps its own stack
 *    trace, and a stack trace can contain the arguments of the frames that built the request
 *    - which is where the Authorization header and the API key live. Laravel's handler
 *    renders `previous` traces into the log, so chaining would publish exactly what
 *    CLAUDE.md rule 4 forbids. The original exception's class name is kept in the context
 *    instead, which is all a maintainer actually needs.
 *
 * 2. **Any provider-supplied text passes through {@see Redactor} first.** A 401 body is the
 *    single most likely place for an endpoint to echo the credential it just rejected.
 *
 * 3. **The message is translated and safe to show.** It names the driver, the status and the
 *    limit that was hit, and never the base URL (which may itself carry credentials), the
 *    request body, or a file path.
 *
 * `context()` carries the machine-readable detail for logs and for the agent's retry
 * decision. It is subject to the same three rules.
 */
final class AiProviderException extends DomainException
{
    public static function connection(string $driver, ?string $detail = null): self
    {
        return new self(
            __('ai.provider.connection_failed', ['provider' => $driver]),
            self::describe($driver, null, true, $detail),
        );
    }

    public static function timeout(string $driver, int $seconds): self
    {
        return new self(
            __('ai.provider.timeout', ['provider' => $driver, 'seconds' => $seconds]),
            self::describe($driver, null, true),
        );
    }

    public static function rateLimited(string $driver, ?int $retryAfter = null): self
    {
        return new self(
            __('ai.provider.rate_limited', ['provider' => $driver]),
            self::describe($driver, 429, true) + ['retry_after' => $retryAfter],
        );
    }

    public static function http(string $driver, int $status, ?string $detail = null): self
    {
        $key = match (true) {
            $status === 401 || $status === 403 => 'ai.provider.unauthorized',
            $status === 404 => 'ai.provider.not_found',
            $status >= 500 => 'ai.provider.server_error',
            default => 'ai.provider.http_error',
        };

        return new self(
            __($key, ['provider' => $driver, 'status' => $status]),
            self::describe($driver, $status, $status >= 500, $detail),
        );
    }

    public static function malformedResponse(string $driver, string $reason): self
    {
        return new self(
            __('ai.provider.malformed_response', ['provider' => $driver]),
            self::describe($driver, null, false) + ['reason' => $reason],
        );
    }

    public static function unexpected(string $driver, ?string $exceptionClass = null): self
    {
        return new self(
            __('ai.provider.unexpected', ['provider' => $driver]),
            self::describe($driver, null, false) + ['exception' => $exceptionClass],
        );
    }

    /**
     * The `ai_providers.driver` value did not resolve to a known driver.
     *
     * The offending value is recorded but never used to build a class name: drivers resolve
     * through a `match` over the enum, so a hostile row cannot name a class to instantiate
     * (AI_SECURITY, "AI to arbitrary PHP").
     */
    public static function unknownDriver(string $driver): self
    {
        return new self(
            __('ai.provider.unknown_driver'),
            ['driver' => mb_substr($driver, 0, 64), 'retryable' => false],
        );
    }

    public static function misconfigured(string $driver, string $reason): self
    {
        return new self(
            __('ai.provider.misconfigured', ['provider' => $driver]),
            self::describe($driver, null, false) + ['reason' => $reason],
        );
    }

    public function status(): ?int
    {
        $status = $this->context()['status'] ?? null;

        return is_int($status) ? $status : null;
    }

    /**
     * Whether trying the same call again could plausibly succeed. The transport layer has
     * already exhausted its own retries by the time this is thrown; this is for the agent
     * loop deciding whether to report a transient fault or a permanent one.
     */
    public function isRetryable(): bool
    {
        return ($this->context()['retryable'] ?? false) === true;
    }

    /**
     * @return array<string, scalar|null>
     */
    private static function describe(string $driver, ?int $status, bool $retryable, ?string $detail = null): array
    {
        $context = [
            'driver' => $driver,
            'status' => $status,
            'retryable' => $retryable,
        ];

        if ($detail !== null && $detail !== '') {
            $context['detail'] = (new Redactor)->redactString($detail);
        }

        return $context;
    }
}
