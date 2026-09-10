<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Contracts\AiChatMessage;
use App\Ai\Contracts\AiChatRequest;
use App\Ai\Contracts\AiChatResponse;
use App\Ai\Contracts\ToolCall;
use App\Enums\AiMessageRole;

/**
 * `POST {base}/chat/completions` - OpenAI and everything that copied its shape.
 *
 * One class serves both the `openai` and `openai_compatible` drivers, which is why
 * {@see self::key()} comes from the configured driver rather than a constant: Azure OpenAI,
 * OpenRouter, Groq, Together, Mistral, Ollama, LM Studio and vLLM all speak this protocol at
 * this path, and the only thing that differs is the base URL.
 *
 * Two compatibility problems are handled explicitly because they are common in the wild:
 *
 * - **Missing `usage`.** Many self-hosted servers omit it. Token counts come back as null;
 *   they are never estimated, because an estimate in `ai_usage_daily` would be
 *   indistinguishable from a measured figure.
 * - **Errors returned with a 200.** Some gateways answer `{"error": {...}}` under a success
 *   status. That is treated as the failure it is rather than parsed into an empty answer.
 */
final class OpenAiCompatibleProvider extends AbstractHttpProvider
{
    protected function endpoint(): string
    {
        return 'chat/completions';
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        $key = $this->config->apiKey();

        // A local endpoint (Ollama, LM Studio, vLLM) usually needs no credential at all;
        // sending an empty bearer would make it look like a malformed one.
        return $key === null || $key === ''
            ? []
            : ['Authorization' => 'Bearer '.$key];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(AiChatRequest $request): array
    {
        $messages = [];

        // The operating instructions lead, always. They are carried outside the message array
        // precisely so nothing can be placed ahead of them here.
        if ($request->systemPrompt !== null && trim($request->systemPrompt) !== '') {
            $messages[] = ['role' => 'system', 'content' => $request->systemPrompt];
        }

        foreach ($request->messages as $message) {
            $messages[] = $this->mapMessage($message);
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
        ];

        if ($request->temperature !== null && $this->config->supportsTemperature) {
            $payload['temperature'] = $request->temperature;
        }

        $maxTokens = $request->maxTokens ?? $this->config->maxTokens;

        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        if ($request->hasTools()) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['parameters'],
                    ],
                ],
                $request->tools,
            );

            $payload['tool_choice'] = 'auto';
        }

        return array_merge(
            $this->extraOptions(['model', 'messages', 'tools', 'tool_choice', 'stream']),
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMessage(AiChatMessage $message): array
    {
        return match ($message->role) {
            AiMessageRole::System => ['role' => 'system', 'content' => $message->text()],
            AiMessageRole::User => ['role' => 'user', 'content' => $message->text()],
            AiMessageRole::Assistant => $this->mapAssistant($message),
            AiMessageRole::Tool => array_filter([
                'role' => 'tool',
                'tool_call_id' => $message->toolCallId ?? '',
                'name' => $message->name,
                'content' => $message->text(),
            ], static fn (mixed $value): bool => $value !== null),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAssistant(AiChatMessage $message): array
    {
        $mapped = [
            'role' => 'assistant',
            // The API accepts a null content alongside tool calls, and an empty string in
            // its place makes some gateways reject the turn.
            'content' => $message->content,
        ];

        if ($message->hasToolCalls()) {
            $mapped['tool_calls'] = array_map(
                static fn (ToolCall $call): array => [
                    'id' => $call->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $call->name,
                        'arguments' => $call->argumentsJson(),
                    ],
                ],
                $message->toolCalls,
            );
        }

        return $mapped;
    }

    /**
     * @param array<array-key, mixed> $body
     */
    protected function parse(array $body, AiChatRequest $request): AiChatResponse
    {
        $choices = $body['choices'] ?? null;

        if (! is_array($choices) || $choices === []) {
            if (isset($body['error'])) {
                throw AiProviderException::http($this->key(), 200, $this->errorDetail($body['error']));
            }

            throw AiProviderException::malformedResponse($this->key(), 'no choices in response');
        }

        $choice = $choices[0] ?? null;
        $message = is_array($choice) ? ($choice['message'] ?? null) : null;

        if (! is_array($message)) {
            throw AiProviderException::malformedResponse($this->key(), 'choice had no message');
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiChatResponse(
            content: is_string($message['content'] ?? null) && $message['content'] !== ''
                ? $message['content']
                : null,
            toolCalls: $this->parseToolCalls($message['tool_calls'] ?? null),
            finishReason: is_string($choice['finish_reason'] ?? null) ? $choice['finish_reason'] : null,
            tokensIn: $this->tokenCount($usage['prompt_tokens'] ?? null),
            tokensOut: $this->tokenCount($usage['completion_tokens'] ?? null),
            model: is_string($body['model'] ?? null) && $body['model'] !== '' ? $body['model'] : $request->model,
            raw: $this->redactedRaw($body),
        );
    }

    /**
     * @return list<ToolCall>
     */
    private function parseToolCalls(mixed $toolCalls): array
    {
        if (! is_array($toolCalls)) {
            return [];
        }

        $calls = [];

        foreach ($toolCalls as $toolCall) {
            if (! is_array($toolCall)) {
                continue;
            }

            $function = is_array($toolCall['function'] ?? null) ? $toolCall['function'] : [];
            $arguments = $function['arguments'] ?? null;

            $calls[] = ToolCall::fromJson(
                is_string($toolCall['id'] ?? null) ? $toolCall['id'] : '',
                is_string($function['name'] ?? null) ? $function['name'] : '',
                is_string($arguments) ? $arguments : null,
            );
        }

        return $calls;
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
