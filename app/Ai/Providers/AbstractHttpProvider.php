<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Contracts\AiChatMessage;
use App\Ai\Contracts\AiChatRequest;
use App\Ai\Contracts\AiChatResponse;
use App\Ai\Contracts\AiProvider;
use App\Ai\Contracts\ProviderHealth;
use App\Ai\Support\Redactor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Shared transport for every HTTP-speaking driver: timeouts, retries, error conversion.
 *
 * # Containment
 *
 * The single most important thing this class does is make sure a credential cannot escape.
 * The API key exists in exactly two places at runtime - inside {@see ProviderConfig}, where it
 * is a private property, and inside the header array handed to the HTTP client. It is never
 * interpolated into a message, never put in a URL, never logged, and never attached to an
 * exception. Every `catch` here converts to {@see AiProviderException} and deliberately drops
 * the original throwable rather than chaining it, because a chained trace can carry the frame
 * arguments that built the request (CLAUDE.md rule 4, AI_SECURITY "What is logged").
 *
 * # Retries
 *
 * Only 429 and 5xx are retried, with exponential backoff, honouring `Retry-After` when the
 * endpoint sends one. A 4xx other than 429 means the request itself is wrong: retrying it
 * burns the budget and cannot succeed. Connection failures get one class of retry too, since
 * a dropped socket on shared hosting is routine.
 *
 * Delays go through {@see Sleep} rather than `usleep()` so the suite can assert the backoff
 * without waiting for it.
 */
abstract class AbstractHttpProvider implements AiProvider
{
    /**
     * A backoff longer than this is worse than failing: the run has a wall-clock limit and
     * the user is waiting.
     */
    private const MAX_BACKOFF_MS = 8000;

    public function __construct(
        protected readonly ProviderConfig $config,
        protected readonly Redactor $redactor = new Redactor,
    ) {}

    public function key(): string
    {
        return $this->config->driver->value;
    }

    public function supportsTools(): bool
    {
        return $this->config->supportsTools;
    }

    public function chat(AiChatRequest $request): AiChatResponse
    {
        $request = $this->prepare($request);

        $body = $this->send($this->payload($request), $this->timeoutFor($request));

        return $this->parse($body, $request);
    }

    /**
     * A minimal round trip for the admin panel.
     *
     * Never throws. A failure is a health result carrying the exception's translated message,
     * which is already credential-free by construction.
     */
    public function testConnection(): ProviderHealth
    {
        $startedAt = hrtime(true);

        try {
            $response = $this->chat(new AiChatRequest(
                messages: [AiChatMessage::user('ping')],
                model: $this->config->model,
                maxTokens: 16,
                systemPrompt: 'Reply with the single word: ok.',
                timeout: min(20, $this->config->timeoutSeconds),
            ));

            return ProviderHealth::reachable(
                __('ai.provider.health_ok'),
                $response->model ?? $this->config->model,
                $this->elapsedMs($startedAt),
            );
        } catch (AiProviderException $e) {
            return ProviderHealth::unreachable(
                $e->userMessage(),
                $this->config->model,
                $this->elapsedMs($startedAt),
            );
        }
    }

    /* ------------------------------------------------------------------ *
     * Driver hooks
     * ------------------------------------------------------------------ */

    /**
     * Path appended to the configured base URL. An empty string posts to the base itself.
     */
    abstract protected function endpoint(): string;

    /**
     * Authentication and protocol headers. Merged last so a custom header configured by an
     * administrator can never displace the credential.
     *
     * @return array<string, string>
     */
    abstract protected function authHeaders(): array;

    /**
     * @return array<string, mixed>
     */
    abstract protected function payload(AiChatRequest $request): array;

    /**
     * @param array<array-key, mixed> $body
     */
    abstract protected function parse(array $body, AiChatRequest $request): AiChatResponse;

    /* ------------------------------------------------------------------ *
     * Transport
     * ------------------------------------------------------------------ */

    /**
     * Normalise a request before it reaches a driver.
     *
     * A driver that cannot call tools has them stripped rather than being sent a payload the
     * endpoint would reject. The agent is expected to have noticed {@see self::supportsTools()}
     * and run in assistant mode already; this is the belt to that braces.
     */
    protected function prepare(AiChatRequest $request): AiChatRequest
    {
        if (! $this->supportsTools() && $request->hasTools()) {
            $request = $request->withoutTools();
        }

        if (trim($request->model) === '') {
            $request = $request->withModel($this->config->model);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<array-key, mixed>
     *
     * @throws AiProviderException
     */
    protected function send(array $payload, int $timeout): array
    {
        $attempts = max(1, $this->config->maxRetries + 1);
        $url = $this->config->url($this->endpoint());

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = Http::withHeaders($this->headers())
                    ->timeout($timeout)
                    ->connectTimeout(min($this->config->connectTimeoutSeconds, $timeout))
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);
            } catch (ConnectionException $e) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt, null);

                    continue;
                }

                // The message is read to tell "nothing answered" from "it answered too
                // slowly" and is then discarded: a transport message can quote the URL, and a
                // URL can carry credentials. Only the classification survives.
                throw $this->timedOut($e->getMessage())
                    ? AiProviderException::timeout($this->key(), $timeout)
                    : AiProviderException::connection($this->key());
            } catch (Throwable $e) {
                throw AiProviderException::unexpected($this->key(), $e::class);
            }

            $status = $response->status();

            if ($status === 429 || $status >= 500) {
                if ($attempt < $attempts) {
                    $this->backoff($attempt, $response->header('Retry-After'));

                    continue;
                }

                throw $status === 429
                    ? AiProviderException::rateLimited($this->key(), $this->retryAfterSeconds($response->header('Retry-After')))
                    : AiProviderException::http($this->key(), $status, $this->safeDetail($response->body()));
            }

            if ($status < 200 || $status >= 300) {
                throw AiProviderException::http($this->key(), $status, $this->safeDetail($response->body()));
            }

            return $this->decode($response);
        }

        // Unreachable: the loop either returns or throws on its final attempt.
        throw AiProviderException::unexpected($this->key());
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws AiProviderException
     */
    protected function decode(Response $response): array
    {
        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw AiProviderException::malformedResponse($this->key(), 'response body was not a JSON object');
        }

        return $decoded;
    }

    /**
     * Administrator headers first, protocol headers last: the credential always wins.
     *
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return array_merge($this->config->headers(), $this->authHeaders());
    }

    /**
     * A request may ask for less time than the provider row allows; neither may exceed the
     * ceiling in config/ai.php.
     */
    protected function timeoutFor(AiChatRequest $request): int
    {
        $configured = config('ai.limits.request_timeout_seconds');
        $ceiling = is_int($configured) && $configured > 0 ? $configured : 60;

        $requested = $request->timeout ?? $this->config->timeoutSeconds;

        return max(1, min($requested, $ceiling));
    }

    /**
     * Body parameters an administrator configured, minus anything that would let them
     * overwrite what the driver is responsible for.
     *
     * @param list<string> $reserved
     * @return array<string, mixed>
     */
    protected function extraOptions(array $reserved): array
    {
        return array_diff_key($this->config->options(), array_flip($reserved));
    }

    /**
     * Exponential backoff, unless the endpoint told us how long to wait.
     */
    protected function backoff(int $attempt, ?string $retryAfter): void
    {
        $seconds = $this->retryAfterSeconds($retryAfter);

        $milliseconds = $seconds !== null
            ? $seconds * 1000
            : $this->config->retryBaseDelayMs * (2 ** ($attempt - 1));

        Sleep::for(min(max($milliseconds, 0), self::MAX_BACKOFF_MS))->milliseconds();
    }

    /**
     * Whether a transport failure was the endpoint being slow rather than absent.
     *
     * Telling an administrator "it did not respond within 60 seconds" when the host is simply
     * unreachable sends them to the wrong place, so the two are reported differently. Only
     * the verdict leaves this method.
     */
    private function timedOut(string $message): bool
    {
        $message = mb_strtolower($message);

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'operation too slow');
    }

    /**
     * `Retry-After` is a delay in seconds or an HTTP date. Anything else is ignored rather
     * than guessed at, and an absurd delay is treated as absent so a hostile or broken
     * endpoint cannot park a worker.
     */
    protected function retryAfterSeconds(?string $header): ?int
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        $header = trim($header);

        if (ctype_digit($header)) {
            $seconds = (int) $header;

            return $seconds >= 0 && $seconds <= 60 ? $seconds : null;
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        $seconds = $timestamp - time();

        return $seconds > 0 && $seconds <= 60 ? $seconds : null;
    }

    /**
     * Only accept a token count the provider actually reported. Guessing would put a
     * fabricated number into `ai_usage_daily` indistinguishable from a measured one.
     */
    protected function tokenCount(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }

    /**
     * The provider payload, redacted, for debugging a misbehaving endpoint.
     *
     * @param array<array-key, mixed> $body
     * @return array<array-key, mixed>
     */
    protected function redactedRaw(array $body): array
    {
        return $this->redactor->redactArray($body);
    }

    /**
     * Scrub provider-supplied text BEFORE it becomes a function argument.
     *
     * Redacting inside the exception would be too late: PHP records the arguments of every
     * frame in a stack trace, so an unredacted body handed to the factory would sit in the
     * trace of an exception that Laravel writes to the log in full. Scrubbing here means the
     * credential never enters a frame in the first place.
     */
    protected function safeDetail(string $detail): string
    {
        return $this->redactor->redactString($detail);
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
