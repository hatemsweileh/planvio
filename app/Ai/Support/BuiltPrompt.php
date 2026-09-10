<?php

declare(strict_types=1);

namespace App\Ai\Support;

use App\Ai\Contracts\AiChatMessage;
use App\Ai\Contracts\AiChatRequest;

/**
 * The finished prompt: what {@see PromptBuilder} produced and what it noticed while producing
 * it.
 *
 * `systemPrompt` is held apart from `messages` so it cannot be reordered or displaced. Turning
 * this into a provider request goes through {@see self::toRequest()}, which is the only route
 * from an assembled prompt to a driver.
 *
 * `injectionFlags` travels with the prompt rather than being written here, because persisting
 * it belongs to the run: the agent records the flags against `ai_runs` and surfaces them to
 * the user. Flags never change what is sent.
 */
final readonly class BuiltPrompt
{
    /**
     * @param list<AiChatMessage> $messages
     * @param list<InjectionFlag> $injectionFlags
     * @param int $droppedMessages context and history dropped oldest-first to fit the budget
     */
    public function __construct(
        public string $systemPrompt,
        public array $messages,
        public array $injectionFlags = [],
        public int $estimatedTokens = 0,
        public bool $truncated = false,
        public int $droppedMessages = 0,
    ) {}

    /**
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     */
    public function toRequest(
        string $model,
        array $tools = [],
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?int $timeout = null,
    ): AiChatRequest {
        return new AiChatRequest(
            messages: $this->messages,
            model: $model,
            tools: $tools,
            temperature: $temperature,
            maxTokens: $maxTokens,
            systemPrompt: $this->systemPrompt,
            timeout: $timeout,
        );
    }

    public function hasInjectionFlags(): bool
    {
        return $this->injectionFlags !== [];
    }

    /**
     * The flags in a shape fit for `ai_runs` and the tool-run log.
     *
     * @return list<array{source: string, pattern: string, match: string|null}>
     */
    public function injectionFlagSummary(): array
    {
        return array_map(
            static fn (InjectionFlag $flag): array => $flag->toArray(),
            $this->injectionFlags,
        );
    }
}
