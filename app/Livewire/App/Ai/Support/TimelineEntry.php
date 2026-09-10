<?php

declare(strict_types=1);

namespace App\Livewire\App\Ai\Support;

use App\Models\AiMessage;
use App\Models\AiToolRun;

/**
 * One thing to draw in a conversation: a turn somebody took, or a call the agent made.
 *
 * The transcript and the audit trail live in two tables — `ai_messages` and `ai_tool_runs` —
 * and a reader needs to see them interleaved in the order they happened.
 * {@see ConversationTimeline} does the interleaving; this is what it hands back.
 */
final readonly class TimelineEntry
{
    public const USER = 'user';

    public const ASSISTANT = 'assistant';

    public const TRACE = 'trace';

    public function __construct(
        public string $kind,
        public ?AiMessage $message = null,
        public ?ToolTrace $trace = null,
        public ?AiToolRun $toolRun = null,
    ) {}

    public static function user(AiMessage $message): self
    {
        return new self(self::USER, message: $message);
    }

    public static function assistant(AiMessage $message): self
    {
        return new self(self::ASSISTANT, message: $message);
    }

    public static function trace(ToolTrace $trace, ?AiToolRun $toolRun = null): self
    {
        return new self(self::TRACE, trace: $trace, toolRun: $toolRun);
    }

    /**
     * Stable across polls, which is what keeps Livewire from re-creating every node in a
     * long thread each time the run advances.
     */
    public function key(): string
    {
        return match ($this->kind) {
            self::TRACE => 'trace-'.($this->trace?->toolRunId ?? 'x').'-'.($this->trace?->tool ?? ''),
            default => 'message-'.($this->message?->getKey() ?? 'x'),
        };
    }

    /**
     * The tool call a person is being asked to decide, or null.
     */
    public function pendingApproval(): ?AiToolRun
    {
        return $this->trace?->awaitingDecision() === true ? $this->toolRun : null;
    }
}
