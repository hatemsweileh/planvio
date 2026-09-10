<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMessageRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiMessage>
 */
final class AiMessageFactory extends Factory
{
    protected $model = AiMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_conversation_id' => AiConversation::factory(),
            'role' => AiMessageRole::User,
            'content' => fake()->sentence(),
            'tool_calls' => null,
            'tool_call_id' => null,
            'name' => null,
            'ai_run_id' => null,
            'tokens_in' => null,
            'tokens_out' => null,
            'error' => null,
        ];
    }

    public function forConversation(AiConversation $conversation): self
    {
        return $this->state(fn (): array => ['ai_conversation_id' => $conversation->getKey()]);
    }

    public function forRun(AiRun $run): self
    {
        return $this->state(fn (): array => ['ai_run_id' => $run->getKey()]);
    }

    public function system(): self
    {
        return $this->state(fn (): array => [
            'role' => AiMessageRole::System,
            'content' => 'Planvio operating instructions.',
        ]);
    }

    public function assistant(?string $content = null): self
    {
        return $this->state(fn (): array => [
            'role' => AiMessageRole::Assistant,
            'content' => $content ?? fake()->paragraph(),
            'tokens_in' => fake()->numberBetween(50, 400),
            'tokens_out' => fake()->numberBetween(20, 300),
        ]);
    }

    /**
     * An assistant turn that asked for a tool instead of answering.
     *
     * @param array<int, array<string, mixed>>|null $calls
     */
    public function withToolCalls(?array $calls = null): self
    {
        return $this->state(fn (): array => [
            'role' => AiMessageRole::Assistant,
            'content' => null,
            'tool_calls' => $calls ?? [[
                'id' => 'call_'.fake()->unique()->numberBetween(1, 999999),
                'name' => 'search_tasks',
                'arguments' => ['query' => 'overdue'],
            ]],
        ]);
    }

    /**
     * The tool's reply, matched to the call it answers.
     */
    public function toolResult(string $toolCallId, string $tool = 'search_tasks'): self
    {
        return $this->state(fn (): array => [
            'role' => AiMessageRole::Tool,
            'tool_call_id' => $toolCallId,
            'name' => $tool,
            'content' => '{"ok":true,"data":[]}',
        ]);
    }

    public function failed(string $error = 'Provider timed out'): self
    {
        return $this->state(fn (): array => [
            'content' => null,
            'error' => $error,
        ]);
    }
}
