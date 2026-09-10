<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Enums\AiDriver;

/**
 * Everything a driver needs to make a call, resolved once by {@see ProviderFactory}.
 *
 * The API key is the reason this is a class and not an array. It is a private property with
 * an explicit accessor, so it is excluded from `json_encode()`, from `get_object_vars()` from
 * outside, and - via {@see self::__debugInfo()} - from `var_dump()`, `dd()` and Laravel's
 * exception page. Nothing accidental prints it; only code that asks for it by name gets it.
 *
 * Limits arrive here already clamped against `config('ai.limits')`. A workspace or a provider
 * row may lower a timeout or a retry count, never raise it (AI_SECURITY, "Execution limits").
 */
final class ProviderConfig
{
    /**
     * @param array<string, string> $headers extra headers configured by an administrator
     * @param array<string, mixed> $options extra body parameters, e.g. top_p
     */
    public function __construct(
        public readonly AiDriver $driver,
        public readonly string $baseUrl,
        private readonly ?string $apiKey,
        public readonly string $model,
        public readonly ?string $fallbackModel = null,
        public readonly ?float $temperature = null,
        public readonly ?int $maxTokens = null,
        public readonly int $timeoutSeconds = 60,
        public readonly int $connectTimeoutSeconds = 10,
        public readonly int $maxRetries = 2,
        public readonly int $retryBaseDelayMs = 800,
        private readonly array $headers = [],
        private readonly array $options = [],
        public readonly bool $supportsTools = true,
        public readonly bool $supportsTemperature = true,
        public readonly ?string $apiVersion = null,
    ) {}

    public function apiKey(): ?string
    {
        return $this->apiKey;
    }

    /**
     * Extra headers an administrator configured. Private for the same reason the key is: a
     * gateway header is a credential often enough that it must not be serialisable by
     * accident.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Extra body parameters. Private alongside the headers, since an endpoint that wants its
     * credential in the body would put it here.
     *
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== null && $this->apiKey !== '';
    }

    /**
     * Absolute URL for a driver endpoint. An empty path returns the base URL itself, which is
     * what a custom endpoint wants: the administrator configured the full URL.
     */
    public function url(string $path = ''): string
    {
        $base = rtrim($this->baseUrl, '/');

        if ($path === '') {
            return $base;
        }

        return $base.'/'.ltrim($path, '/');
    }

    /**
     * Keeps the key out of `var_dump()`, `dd()`, `ray()` and the Ignition exception page.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'driver' => $this->driver->value,
            'baseUrl' => $this->baseUrl,
            'apiKey' => $this->hasApiKey() ? '[redacted]' : null,
            'model' => $this->model,
            'fallbackModel' => $this->fallbackModel,
            'temperature' => $this->temperature,
            'maxTokens' => $this->maxTokens,
            'timeoutSeconds' => $this->timeoutSeconds,
            'connectTimeoutSeconds' => $this->connectTimeoutSeconds,
            'maxRetries' => $this->maxRetries,
            'retryBaseDelayMs' => $this->retryBaseDelayMs,
            'headers' => array_map(static fn (): string => '[redacted]', $this->headers),
            'options' => array_map(static fn (): string => '[redacted]', $this->options),
            'supportsTools' => $this->supportsTools,
            'supportsTemperature' => $this->supportsTemperature,
            'apiVersion' => $this->apiVersion,
        ];
    }
}
