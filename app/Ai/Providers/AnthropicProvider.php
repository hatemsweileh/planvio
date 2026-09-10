<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Contracts\AiChatMessage;
use App\Ai\Contracts\AiChatRequest;
use App\Ai\Contracts\AiChatResponse;
use App\Ai\Contracts\ToolCall;
use App\Ai\Support\PromptBuilder;
use App\Enums\AiMessageRole;
use stdClass;

/**
 * `POST {base}/messages` - the Anthropic Messages API.
 *
 * This is not an OpenAI-shaped endpoint with different headers; it differs structurally, and
 * each difference is mapped explicitly in both directions:
 *
 * | Planvio                       | OpenAI                       | Anthropic                                    |
 * |-------------------------------|------------------------------|----------------------------------------------|
 * | system prompt                 | first `system` message       | top-level `system` field, not a message      |
 * | tool definition               | `function.parameters`        | `input_schema`                               |
 * | model proposes a call         | `tool_calls[]`               | `tool_use` content block                     |
 * | result of a call              | `tool` role message          | `user` message with a `tool_result` block    |
 * | credential                    | `Authorization: Bearer`      | `x-api-key` plus `anthropic-version`         |
 * | answer length                 | `max_tokens` optional        | `max_tokens` REQUIRED                        |
 *
 * Two structural rules the API enforces and this class therefore has to honour: messages must
 * alternate between roles, and every `tool_result` for one assistant turn has to arrive in a
 * single user message. Consecutive same-role messages are merged for exactly that reason -
 * a run with three parallel tool calls produces three tool results, and sending them as three
 * user messages is rejected.
 *
 * Any System-role message inside the conversation is folded into the top-level `system` field,
 * because the API has no system role in `messages`. {@see PromptBuilder} only
 * ever emits one, immediately after the operating instructions, so the fold preserves order.
 */
final class AnthropicProvider extends AbstractHttpProvider
{
    private const DEFAULT_API_VERSION = '2023-06-01';

    /**
     * The API rejects a request without it, so a request that did not set one still has to
     * carry a number. This is a ceiling on the answer, not a budget for the run.
     */
    private const DEFAULT_MAX_TOKENS = 4096;

    protected function endpoint(): string
    {
        return 'messages';
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        $headers = [
            'anthropic-version' => $this->config->apiVersion ?? self::DEFAULT_API_VERSION,
        ];

        $key = $this->config->apiKey();

        if ($key !== null && $key !== '') {
            $headers['x-api-key'] = $key;
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiChatRequest $request): array
    {
        [$system, $messages] = $this->mapConversation($request);

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens ?? $this->config->maxTokens ?? self::DEFAULT_MAX_TOKENS,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->temperature !== null && $this->config->supportsTemperature) {
            $payload['temperature'] = $request->temperature;
        }

        if ($request->hasTools()) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['parameters'],
                ],
                $request->tools,
            );
        }

        return array_merge(
            $this->extraOptions(['model', 'messages', 'system', 'tools', 'max_tokens', 'stream']),
            $payload,
        );
    }

    /**
     * @return array{0: string, 1: list<array<string, mixed>>}
     */
    private function mapConversation(AiChatRequest $request): array
    {
        $system = [];

        if ($request->systemPrompt !== null && trim($request->systemPrompt) !== '') {
            $system[] = trim($request->systemPrompt);
        }

        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === AiMessageRole::System) {
                if (trim($message->text()) !== '') {
                    $system[] = trim($message->text());
                }

                continue;
            }

            $mapped = $this->mapMessage($message);

            if ($mapped === null) {
                continue;
            }

            $messages[] = $mapped;
        }

        return [implode("\n\n", $system), $this->mergeAdjacent($messages)];
    }

    /**
     * @return array{role: string, content: list<array<string, mixed>>}|null
     */
    private function mapMessage(AiChatMessage $message): ?array
    {
        return match ($message->role) {
            AiMessageRole::User => $this->textMessage('user', $message->text()),
            AiMessageRole::Assistant => $this->mapAssistant($message),
            AiMessageRole::Tool => [
                'role' => 'user',
                'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => $message->toolCallId ?? '',
                    'content' => $message->text(),
                ]],
            ],
            // Folded into the top-level system field before this point.
            AiMessageRole::System => null,
        };
    }

    /**
     * @return array{role: string, content: list<array<string, mixed>>}|null
     */
    private function mapAssistant(AiChatMessage $message): ?array
    {
        $content = [];

        if (trim($message->text()) !== '') {
            $content[] = ['type' => 'text', 'text' => $message->text()];
        }

        foreach ($message->toolCalls as $call) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $call->id,
                'name' => $call->name,
                // An empty PHP array encodes as `[]`; the API expects an object here.
                'input' => $call->arguments === [] ? new stdClass : $call->arguments,
            ];
        }

        // An assistant turn with neither text nor tool calls has no representation here, and
        // an empty content array is rejected.
        return $content === [] ? null : ['role' => 'assistant', 'content' => $content];
    }

    /**
     * @return array{role: string, content: list<array<string, mixed>>}|null
     */
    private function textMessage(string $role, string $text): ?array
    {
        return trim($text) === ''
            ? null
            : ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]]];
    }

    /**
     * Merge runs of same-role messages into one, which is both what the API requires and what
     * makes parallel tool results legal.
     *
     * @param list<array<string, mixed>> $messages
     * @return list<array<string, mixed>>
     */
    private function mergeAdjacent(array $messages): array
    {
        $merged = [];

        foreach ($messages as $message) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['role'] === $message['role']) {
                $merged[$last]['content'] = array_merge($merged[$last]['content'], $message['content']);

                continue;
            }

            $merged[] = $message;
        }

        return $merged;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    protected function parse(array $body, AiChatRequest $request): AiChatResponse
    {
        if (($body['type'] ?? null) === 'error') {
            throw AiProviderException::http($this->key(), 200, $this->errorDetail($body['error'] ?? null));
        }

        $blocks = $body['content'] ?? null;

        if (! is_array($blocks)) {
            throw AiProviderException::malformedResponse($this->key(), 'response had no content blocks');
        }

        $text = '';
        $toolCalls = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];

                continue;
            }

            if ($type === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    id: is_string($block['id'] ?? null) ? $block['id'] : '',
                    name: is_string($block['name'] ?? null) ? $block['name'] : '',
                    arguments: is_array($block['input'] ?? null) ? $block['input'] : [],
                );
            }
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiChatResponse(
            content: $text === '' ? null : $text,
            toolCalls: $toolCalls,
            finishReason: is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : null,
            tokensIn: $this->tokenCount($usage['input_tokens'] ?? null),
            tokensOut: $this->tokenCount($usage['output_tokens'] ?? null),
            model: is_string($body['model'] ?? null) && $body['model'] !== '' ? $body['model'] : $request->model,
            raw: $this->redactedRaw($body),
        );
    }

    /**
     * Scrubbed here rather than inside the exception: an unredacted string handed to the
     * factory would be recorded as a frame argument in the stack trace.
     */
    private function errorDetail(mixed $error): ?string
    {
        $detail = match (true) {
            is_string($error) => $error,
            is_array($error) && is_string($error['message'] ?? null) => $error['message'],
            default => null,
        };

        return $detail === null ? null : $this->safeDetail($detail);
    }
}
