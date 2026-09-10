<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Enums\AiDriver;
use App\Enums\AiMode;
use SensitiveParameter;

/**
 * The optional AI configuration, or the decision to leave AI switched off.
 *
 * Off is the shipped default and a complete answer: Planvio is a project-management tool that
 * happens to have an agent, not an agent that happens to store projects, and an installation
 * that phoned a third party nobody had chosen would be a surprise rather than a feature
 * (ARCHITECTURE.md §5.8). {@see self::disabled()} is what the wizard produces when the step
 * is skipped, and the installer runs the AI step either way — it simply records that AI is
 * off and moves on.
 *
 * The key lives in this object for the length of one installation and is written to the
 * `ai_providers` table under the encrypted cast. It is never written to `.env`, never logged,
 * and never sent back to the browser after it has been saved (spec §72).
 */
final readonly class AiCredentials
{
    private function __construct(
        public bool $enabled,
        public AiDriver $driver,
        public ?string $baseUrl,
        #[SensitiveParameter]
        public ?string $apiKey,
        public ?string $model,
        public AiMode $mode,
    ) {}

    public static function disabled(): self
    {
        return new self(false, AiDriver::OpenAi, null, null, null, AiMode::Assistant);
    }

    public static function enabled(
        AiDriver $driver,
        ?string $baseUrl,
        #[SensitiveParameter]
        ?string $apiKey,
        ?string $model,
        AiMode $mode,
    ): self {
        return new self(
            true,
            $driver,
            self::clean($baseUrl),
            self::clean($apiKey),
            self::clean($model),
            $mode,
        );
    }

    /**
     * The provider name stored on the `ai_providers` row.
     */
    public function providerName(): string
    {
        $label = config('ai.drivers.'.$this->driver->value.'.label');

        return is_string($label) && $label !== '' ? $label : $this->driver->value;
    }

    /**
     * The base URL to use, falling back to the driver's shipped default.
     */
    public function resolvedBaseUrl(): ?string
    {
        if ($this->baseUrl !== null) {
            return rtrim($this->baseUrl, '/');
        }

        $default = config('ai.drivers.'.$this->driver->value.'.base_url');

        return is_string($default) && $default !== '' ? $default : null;
    }

    public function resolvedModel(): ?string
    {
        if ($this->model !== null) {
            return $this->model;
        }

        $default = config('ai.drivers.'.$this->driver->value.'.default_model');

        return is_string($default) && $default !== '' ? $default : null;
    }

    /**
     * Whether the chosen driver has no shipped endpoint and must be told one.
     */
    public function requiresBaseUrl(): bool
    {
        return (bool) config('ai.drivers.'.$this->driver->value.'.requires_base_url', false);
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
