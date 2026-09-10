<?php

declare(strict_types=1);

namespace App\Ai\Support;

use App\Ai\Contracts\AiChatMessage;
use App\Ai\Contracts\ToolCall;

/**
 * A deliberately approximate token count.
 *
 * Four characters per token is the rough average for English across the BPE vocabularies the
 * supported providers use. It is wrong for code, wrong for CJK, and wrong for any single
 * short string — and that is acceptable, because this exists to BOUND CONTEXT, not to bill
 * anyone. Real token counts come back from the provider in `usage` and are the only figures
 * ever written to `ai_runs` or `ai_usage_daily`.
 *
 * Bringing in a real tokeniser would mean a Composer dependency with a multi-megabyte
 * vocabulary per model family, which the deployment invariants (ARCHITECTURE.md section 9)
 * rule out, and would still be wrong for whichever endpoint an administrator points at.
 *
 * The estimate rounds up and adds a small per-message overhead, so the builder errs towards
 * sending less than the budget rather than more.
 */
final class TokenEstimator
{
    private const CHARS_PER_TOKEN = 4;

    /**
     * Role, separators and the message envelope cost a handful of tokens whatever the body.
     */
    private const MESSAGE_OVERHEAD_TOKENS = 4;

    public function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    public function estimateMessage(AiChatMessage $message): int
    {
        $tokens = self::MESSAGE_OVERHEAD_TOKENS + $this->estimate($message->text());

        if ($message->name !== null) {
            $tokens += $this->estimate($message->name);
        }

        if ($message->toolCallId !== null) {
            $tokens += $this->estimate($message->toolCallId);
        }

        foreach ($message->toolCalls as $call) {
            $tokens += $this->estimateToolCall($call);
        }

        return $tokens;
    }

    /**
     * @param list<AiChatMessage> $messages
     */
    public function estimateMessages(array $messages): int
    {
        $total = 0;

        foreach ($messages as $message) {
            $total += $this->estimateMessage($message);
        }

        return $total;
    }

    public function estimateToolCall(ToolCall $call): int
    {
        return self::MESSAGE_OVERHEAD_TOKENS
            + $this->estimate($call->name)
            + $this->estimate($call->argumentsJson());
    }

    /**
     * The tool definitions sent alongside the conversation. Schemas are not free and a full
     * v1 tool set is a meaningful slice of the budget.
     *
     * @param list<array{name: string, description: string, parameters: array<string, mixed>}> $tools
     */
    public function estimateTools(array $tools): int
    {
        $encoded = json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? 0 : $this->estimate($encoded);
    }

    /**
     * Cut text down to fit a token allowance, on a character boundary.
     *
     * Callers truncate RAW content before it is wrapped, never the wrapped string: slicing a
     * wrapped block would drop its closing tag and let everything after it read as though it
     * were still inside the wrapper.
     */
    public function truncateToTokens(string $text, int $maxTokens): string
    {
        if ($maxTokens <= 0) {
            return '';
        }

        if ($this->estimate($text) <= $maxTokens) {
            return $text;
        }

        $marker = '... [truncated]';
        $allowance = ($maxTokens * self::CHARS_PER_TOKEN) - mb_strlen($marker);

        if ($allowance <= 0) {
            return '';
        }

        return mb_substr($text, 0, $allowance).$marker;
    }
}
