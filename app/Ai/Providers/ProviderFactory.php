<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Contracts\AiProvider as AiProviderContract;
use App\Ai\Support\Redactor;
use App\Enums\AiDriver;
use App\Models\AiProvider as AiProviderModel;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Turns an `ai_providers` row into a driver.
 *
 * # Why this is a `match` and not a container resolve
 *
 * `config('ai.drivers')` carries a `class` key, and reading it to instantiate the driver would
 * be the obvious implementation. It is not the one used here. The driver name arrives from the
 * database, and a code path of the shape "take a value that came from a row, look up a class
 * name, instantiate it" is precisely the dynamic-instantiation-from-data pattern that
 * AI_SECURITY says does not exist anywhere in this codebase. So the mapping is a `match` over
 * the {@see AiDriver} enum: exhaustive, checked by the compiler, and impossible to steer with
 * a crafted row. A value the enum does not know throws {@see AiProviderException::unknownDriver()}
 * and is never treated as a name of anything.
 *
 * The rest of the driver definition - base URL default, tool support, API version - still comes
 * from config, because those are settings rather than code paths.
 *
 * # Fail closed
 *
 * A provider row that cannot produce a working call is rejected here rather than at the moment
 * the model is waiting: a missing base URL for a driver that requires one, a base URL that is
 * not http(s), or no model to call. A key that cannot be decrypted (APP_KEY rotated) is
 * treated as absent, which some endpoints legitimately allow and the rest will answer 401 to.
 */
final class ProviderFactory
{
    public function __construct(
        private readonly Redactor $redactor = new Redactor,
    ) {}

    /**
     * @throws AiProviderException
     */
    public function make(AiProviderModel $model): AiProviderContract
    {
        $driver = self::driverOf($model);
        $definition = self::definition($driver);
        $config = $this->configFor($model, $driver, $definition);

        return match ($driver) {
            AiDriver::OpenAi, AiDriver::OpenAiCompatible => new OpenAiCompatibleProvider($config, $this->redactor),
            AiDriver::Anthropic => new AnthropicProvider($config, $this->redactor),
            AiDriver::CustomHttp => new CustomHttpProvider($config, $this->redactor),
        };
    }

    /**
     * Whether a driver can be given tools, without having to build one.
     *
     * The agent loop uses this to decide between copilot/autonomous and assistant mode before
     * a provider is instantiated.
     */
    public static function supportsTools(AiDriver $driver): bool
    {
        return (bool) (self::definition($driver)['supports_tools'] ?? false);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AiProviderException
     */
    public static function definition(AiDriver $driver): array
    {
        $definition = config('ai.drivers.'.$driver->value);

        if (! is_array($definition)) {
            throw AiProviderException::unknownDriver($driver->value);
        }

        return $definition;
    }

    /**
     * Read the driver without letting the model's enum cast throw.
     *
     * A row written before a driver was removed, or edited directly in the database, must
     * produce a clean domain failure rather than a `ValueError` from the cast.
     *
     * @throws AiProviderException
     */
    private static function driverOf(AiProviderModel $model): AiDriver
    {
        $raw = $model->getAttributes()['driver'] ?? null;
        $driver = is_string($raw) ? AiDriver::tryFrom($raw) : null;

        if ($driver === null) {
            throw AiProviderException::unknownDriver(is_string($raw) ? $raw : 'null');
        }

        return $driver;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @throws AiProviderException
     */
    private function configFor(AiProviderModel $model, AiDriver $driver, array $definition): ProviderConfig
    {
        $limits = self::limits();

        return new ProviderConfig(
            driver: $driver,
            baseUrl: $this->baseUrl($model, $driver, $definition),
            apiKey: self::apiKey($model),
            model: $this->model($model, $driver, $definition),
            fallbackModel: self::nullableString($model->fallback_model),
            temperature: is_numeric($model->temperature) ? (float) $model->temperature : null,
            maxTokens: is_int($model->max_tokens) && $model->max_tokens > 0 ? $model->max_tokens : null,
            timeoutSeconds: self::clamp(
                is_int($model->timeout_seconds) ? $model->timeout_seconds : $limits['request_timeout_seconds'],
                1,
                $limits['request_timeout_seconds'],
            ),
            connectTimeoutSeconds: $limits['connect_timeout_seconds'],
            maxRetries: $limits['max_retries'],
            retryBaseDelayMs: $limits['retry_base_delay_ms'],
            headers: self::stringMap($model->headers),
            options: is_array($model->options) ? $model->options : [],
            supportsTools: (bool) ($definition['supports_tools'] ?? false),
            supportsTemperature: (bool) ($definition['supports_temperature'] ?? false),
            apiVersion: self::nullableString($definition['api_version'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @throws AiProviderException
     */
    private function baseUrl(AiProviderModel $model, AiDriver $driver, array $definition): string
    {
        $configured = self::nullableString($model->base_url)
            ?? self::nullableString($definition['base_url'] ?? null);

        if ($configured === null) {
            throw AiProviderException::misconfigured($driver->value, 'no base URL configured');
        }

        $scheme = parse_url($configured, PHP_URL_SCHEME);

        // Anything but http(s) - file://, gopher://, a bare host - is rejected rather than
        // handed to the HTTP client to interpret.
        if (! in_array($scheme, ['http', 'https'], true) || parse_url($configured, PHP_URL_HOST) === null) {
            throw AiProviderException::misconfigured($driver->value, 'base URL must be an absolute http(s) URL');
        }

        return rtrim($configured, '/');
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @throws AiProviderException
     */
    private function model(AiProviderModel $model, AiDriver $driver, array $definition): string
    {
        $name = self::nullableString($model->model)
            ?? self::nullableString($definition['default_model'] ?? null);

        if ($name === null) {
            throw AiProviderException::misconfigured($driver->value, 'no model configured');
        }

        return $name;
    }

    /**
     * A key encrypted under a rotated APP_KEY is unreadable, not a fatal error: the call will
     * fail at the endpoint with a clean 401 instead of a decryption stack trace.
     */
    private static function apiKey(AiProviderModel $model): ?string
    {
        try {
            $key = $model->api_key;
        } catch (DecryptException) {
            return null;
        }

        return is_string($key) ? self::nullableString($key) : null;
    }

    /**
     * @return array{request_timeout_seconds: int, connect_timeout_seconds: int, max_retries: int, retry_base_delay_ms: int}
     */
    private static function limits(): array
    {
        return [
            'request_timeout_seconds' => self::positiveInt(config('ai.limits.request_timeout_seconds'), 60),
            'connect_timeout_seconds' => self::positiveInt(config('ai.limits.connect_timeout_seconds'), 10),
            'max_retries' => max(0, self::positiveInt(config('ai.limits.max_retries'), 2)),
            'retry_base_delay_ms' => self::positiveInt(config('ai.limits.retry_base_delay_ms'), 800),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && is_scalar($item)) {
                $map[$key] = (string) $item;
            }
        }

        return $map;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function positiveInt(mixed $value, int $fallback): int
    {
        return is_int($value) && $value > 0 ? $value : $fallback;
    }

    private static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($value, $max));
    }
}
