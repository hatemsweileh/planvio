<?php

declare(strict_types=1);

namespace App\Filament\Support;

/**
 * One health check's answer, together with what it is based on.
 *
 * The `evidence` list is not decoration. A health screen that says "Healthy" and nothing else
 * is asking to be believed; one that says "Healthy — heartbeat written 41 seconds ago" can be
 * checked by the person reading it, and disbelieved when it is wrong. Every check on this page
 * states what it actually observed, and a check that observed nothing says so instead of
 * reporting green.
 */
final readonly class HealthCheck
{
    /**
     * @param list<string> $evidence what was observed, in the reader's language
     * @param ?string $remedy what to do about it, when there is something to do
     */
    public function __construct(
        public string $key,
        public string $label,
        public HealthStatus $status,
        public string $summary,
        public array $evidence = [],
        public ?string $remedy = null,
    ) {}

    /**
     * @param list<string> $evidence
     */
    public static function healthy(string $key, string $label, string $summary, array $evidence = []): self
    {
        return new self($key, $label, HealthStatus::Healthy, $summary, $evidence);
    }

    /**
     * @param list<string> $evidence
     */
    public static function warning(
        string $key,
        string $label,
        string $summary,
        array $evidence = [],
        ?string $remedy = null,
    ): self {
        return new self($key, $label, HealthStatus::Warning, $summary, $evidence, $remedy);
    }

    /**
     * @param list<string> $evidence
     */
    public static function failed(
        string $key,
        string $label,
        string $summary,
        array $evidence = [],
        ?string $remedy = null,
    ): self {
        return new self($key, $label, HealthStatus::Failed, $summary, $evidence, $remedy);
    }
}
