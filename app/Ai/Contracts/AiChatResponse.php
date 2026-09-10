<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Support\Redactor;

/**
 * What a provider returned, normalised.
 *
 * Token counts are nullable on purpose. Plenty of OpenAI-compatible endpoints omit `usage`
 * entirely; reporting null is honest, whereas an estimate written into `ai_runs.tokens_in`
 * would be indistinguishable from a real figure once it reached the usage rollup.
 *
 * `raw` has already been through {@see Redactor}. It exists for debugging a
 * misbehaving endpoint, so it must never be able to carry a credential into a log.
 */
final readonly class AiChatResponse
{
    /**
     * @param list<ToolCall> $toolCalls
     * @param array<array-key, mixed> $raw redacted provider payload
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls = [],
        public ?string $finishReason = null,
        public ?int $tokensIn = null,
        public ?int $tokensOut = null,
        public ?string $model = null,
        public array $raw = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function text(): string
    {
        return $this->content ?? '';
    }

    /**
     * Tool calls the agent can actually attempt: one missing an id or a name, or whose
     * arguments would not decode, is not among them.
     *
     * @return list<ToolCall>
     */
    public function usableToolCalls(): array
    {
        return array_values(array_filter(
            $this->toolCalls,
            static fn (ToolCall $call): bool => $call->isUsable(),
        ));
    }
}
