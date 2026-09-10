<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

/**
 * A single provider call: the assembled conversation plus the knobs a driver may set.
 *
 * `systemPrompt` is deliberately not part of `messages`. Anthropic takes it as a top-level
 * field rather than a message, and keeping it apart means no caller can push workspace
 * content ahead of the operating instructions by prepending to the array.
 *
 * `tools` is provider-neutral: a list of name/description/parameters where `parameters` is a
 * JSON Schema object. Each driver maps that into its own tool format.
 */
final readonly class AiChatRequest
{
    /**
     * @param list<AiChatMessage> $messages
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     * @param int|null $timeout seconds; clamped by the driver to the configured ceiling
     */
    public function __construct(
        public array $messages,
        public string $model,
        public array $tools = [],
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?string $systemPrompt = null,
        public ?int $timeout = null,
    ) {}

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }

    /**
     * Used by drivers that cannot call tools, so a misconfigured run degrades to a plain
     * answer instead of sending a payload the endpoint would reject.
     */
    public function withoutTools(): self
    {
        return new self(
            $this->messages,
            $this->model,
            [],
            $this->temperature,
            $this->maxTokens,
            $this->systemPrompt,
            $this->timeout,
        );
    }

    public function withModel(string $model): self
    {
        return new self(
            $this->messages,
            $model,
            $this->tools,
            $this->temperature,
            $this->maxTokens,
            $this->systemPrompt,
            $this->timeout,
        );
    }

    /**
     * @param list<AiChatMessage> $messages
     */
    public function withMessages(array $messages): self
    {
        return new self(
            $messages,
            $this->model,
            $this->tools,
            $this->temperature,
            $this->maxTokens,
            $this->systemPrompt,
            $this->timeout,
        );
    }
}
