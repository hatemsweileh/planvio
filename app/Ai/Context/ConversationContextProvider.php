<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Enums\AiMessageRole;
use App\Models\AiMessage;

/**
 * The recent turns of the conversation this run belongs to.
 *
 * One fragment per message, oldest first — and that shape is deliberate. When the budget is
 * tight, {@see ContextBuilder} drops the earliest-emitted fragment of the least durable kind
 * first, so a conversation naturally loses its opening turns and keeps the ones nearest the
 * request. A single block would have to go all at once, which would strand the model in the
 * middle of an exchange it can no longer see the start or the end of.
 *
 * System messages are skipped: the system prompt is rebuilt from `resources/ai/system.md` for
 * every run, and replaying a stored copy would put a second, older instruction set in the
 * prompt — the one thing the injection defence is designed to prevent from happening by
 * accident.
 *
 * Assistant turns are untrusted like everything else. A previous turn may itself have
 * repeated something it read out of a task description, and a message's origin does not
 * launder its content.
 */
final class ConversationContextProvider implements ContextSource
{
    use ContributesContext;

    public function key(): string
    {
        return 'conversation';
    }

    public function supports(AgentContext $context): bool
    {
        $conversation = $context->conversation;

        return $conversation !== null
            && $context->isInWorkspace($conversation)
            && $context->allows('view', $conversation);
    }

    public function provide(AgentContext $context): array
    {
        $conversation = $context->conversation;

        if ($conversation === null) {
            return [];
        }

        $conversationId = (int) $conversation->getKey();

        $messages = AiMessage::query()
            ->where('ai_conversation_id', $conversationId)
            ->whereIn('role', [
                AiMessageRole::User->value,
                AiMessageRole::Assistant->value,
                AiMessageRole::Tool->value,
            ])
            ->orderByDesc('id')
            ->limit($this->limit('conversation_history_messages', 20))
            ->get()
            // Newest first for the cap — the tail of a conversation is the useful part —
            // then reversed so the prompt reads in the order it was said.
            ->reverse()
            ->values();

        $fragments = [];

        foreach ($messages as $message) {
            $content = $this->body($message);

            if ($content === null) {
                continue;
            }

            $fragments[] = ContextFragment::make(
                'conversation:'.$conversationId.':'.(int) $message->getKey(),
                $this->speaker($message).': '.$content,
            );
        }

        return $fragments;
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * The message text, or a stand-in when the turn was a tool request that carried none.
     *
     * An assistant turn that only asked for tools has null content but is not nothing: the
     * names it asked for are the record of what it tried, and dropping them would leave the
     * following tool results unexplained.
     */
    private function body(AiMessage $message): ?string
    {
        $content = Facts::excerpt($message->content);

        if ($content !== null) {
            return $content;
        }

        if (! $message->hasToolCalls()) {
            return null;
        }

        $names = [];

        foreach ((array) $message->tool_calls as $call) {
            $name = is_array($call) ? ($call['name'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        return $names === [] ? null : '(requested tools: '.implode(', ', $names).')';
    }

    private function speaker(AiMessage $message): string
    {
        return match ($message->role) {
            AiMessageRole::User => 'user',
            AiMessageRole::Assistant => 'assistant',
            AiMessageRole::Tool => 'tool result'.($message->name === null ? '' : ' from '.$message->name),
            default => 'message',
        };
    }

    private function limit(string $key, int $default): int
    {
        $configured = config('ai.context.'.$key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}
