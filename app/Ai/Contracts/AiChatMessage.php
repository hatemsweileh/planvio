<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Support\PromptBuilder;
use App\Enums\AiMessageRole;

/**
 * One message in a provider conversation, in Planvio's own shape.
 *
 * Providers translate this into their wire format; nothing outside
 * {@see PromptBuilder} may construct the messages that make up a request
 * (ARCHITECTURE.md section 7.6). The named constructors exist so the builder and the agent
 * loop never have to remember which fields belong to which role.
 *
 * There is no `developer` role: the transcript roles are exactly the four in
 * {@see AiMessageRole}, matching the `ai_messages.role` column. Developer content is carried
 * as a System-role message immediately after the operating instructions.
 */
final readonly class AiChatMessage
{
    /**
     * @param list<ToolCall> $toolCalls set only on an assistant message that proposed tools
     * @param string|null $toolCallId set only on a tool message, matching the call it answers
     * @param string|null $name the tool name, on a tool message
     */
    public function __construct(
        public AiMessageRole $role,
        public ?string $content = null,
        public array $toolCalls = [],
        public ?string $toolCallId = null,
        public ?string $name = null,
    ) {}

    public static function system(string $content): self
    {
        return new self(AiMessageRole::System, $content);
    }

    public static function user(string $content): self
    {
        return new self(AiMessageRole::User, $content);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    public static function assistant(?string $content, array $toolCalls = []): self
    {
        return new self(AiMessageRole::Assistant, $content, $toolCalls);
    }

    /**
     * A tool result. The content must be the RAW result text: wrapping it in
     * `<untrusted-data>` is the prompt builder job and happens exactly once, there.
     */
    public static function tool(string $toolCallId, string $name, string $content): self
    {
        return new self(AiMessageRole::Tool, $content, [], $toolCallId, $name);
    }

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function text(): string
    {
        return $this->content ?? '';
    }

    public function withContent(?string $content): self
    {
        return new self($this->role, $content, $this->toolCalls, $this->toolCallId, $this->name);
    }
}
