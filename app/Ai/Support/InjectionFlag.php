<?php

declare(strict_types=1);

namespace App\Ai\Support;

/**
 * A note that retrieved content matched a known injection phrasing.
 *
 * A flag never blocks anything. Pattern matching on natural language is trivially evaded, and
 * treating it as a control would create false confidence in a defence that does not hold
 * (AI_SECURITY, "Detection as a signal, not a control"). What it does is put the incident in
 * front of a human: the run carries its flags, and the source says which record to go and read.
 *
 * `match` — the offending substring — is only populated when the administrator has explicitly
 * turned on `AI_STORE_PROMPTS`, because it is workspace content and prompt bodies are not
 * persisted by default.
 */
final readonly class InjectionFlag
{
    public function __construct(
        public string $source,
        public string $pattern,
        public ?string $match = null,
    ) {}

    /**
     * @return array{source: string, pattern: string, match: string|null}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'pattern' => $this->pattern,
            'match' => $this->match,
        ];
    }
}
