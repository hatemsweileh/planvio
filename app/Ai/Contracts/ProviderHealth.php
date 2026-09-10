<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

/**
 * The result of an administrator pressing "Test connection".
 *
 * `message` is rendered in the admin panel, so it is always a translated sentence and never
 * a raw provider error: an authentication failure is exactly the moment an endpoint is most
 * likely to echo back the key it just rejected (CLAUDE.md rule 4).
 */
final readonly class ProviderHealth
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $model = null,
        public ?int $latencyMs = null,
    ) {}

    public static function reachable(string $message, ?string $model = null, ?int $latencyMs = null): self
    {
        return new self(true, $message, $model, $latencyMs);
    }

    public static function unreachable(string $message, ?string $model = null, ?int $latencyMs = null): self
    {
        return new self(false, $message, $model, $latencyMs);
    }
}
