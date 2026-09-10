<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Contracts\AiChatRequest;
use App\Ai\Contracts\AiChatResponse;
use App\Enums\AiMessageRole;

/**
 * A plain JSON POST to whatever URL an administrator configured.
 *
 * This is the escape hatch for an internal gateway, a proxy, or a model server that speaks
 * neither dialect. It reports {@see self::supportsTools()} as false, which is not a detail:
 * a provider that cannot call tools can only answer questions, so the agent runs it in
 * assistant mode - no mutation is reachable through it at all. Tools handed to it anyway are
 * stripped by {@see AbstractHttpProvider::prepare()} rather than sent to an endpoint that
 * would reject or, worse, misinterpret them.
 *
 * The request is intentionally boring - model, system, messages - because the point is to be
 * easy to put a small adapter in front of. Responses are read from the handful of shapes such
 * adapters actually return; anything else is a malformed response, not a silent empty answer.
 */
final class CustomHttpProvider extends AbstractHttpProvider
{
    /**
     * Checked in order. The first one present and non-empty wins.
     *
     * @var list<list<string>>
     */
    private const CONTENT_PATHS = [
        ['content'],
        ['output'],
        ['text'],
        ['response'],
        ['message', 'content'],
        ['data', 'content'],
        ['choices', '0', 'message', 'content'],
    ];

    public function supportsTools(): bool
    {
        return false;
    }

    /**
     * The administrator configures the full endpoint, so nothing is appended to it.
     */
    protected function endpoint(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(): array
    {
        $key = $this->config->apiKey();

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

        foreach ($request->messages as $message) {
            $text = $message->text();

            if (trim($text) === '') {
                continue;
            }

            $messages[] = [
                // There is no tool role in this dialect. A tool result becomes a user turn,
                // still wrapped, so the endpoint sees it as data the way every other
                // workspace-derived string is.
                'role' => $message->role === AiMessageRole::Assistant ? 'assistant' : 'user',
                'content' => $text,
            ];
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
        ];

        if ($request->systemPrompt !== null && trim($request->systemPrompt) !== '') {
            $payload['system'] = $request->systemPrompt;
        }

        if ($request->temperature !== null && $this->config->supportsTemperature) {
            $payload['temperature'] = $request->temperature;
        }

        $maxTokens = $request->maxTokens ?? $this->config->maxTokens;

        if ($maxTokens !== null) {
            $payload['max_tokens'] = $maxTokens;
        }

        return array_merge(
            $this->extraOptions(['model', 'messages', 'system', 'stream']),
            $payload,
        );
    }

    /**
     * @param array<array-key, mixed> $body
     */
    protected function parse(array $body, AiChatRequest $request): AiChatResponse
    {
        $content = null;

        foreach (self::CONTENT_PATHS as $path) {
            $value = $this->dig($body, $path);

            if (is_string($value) && trim($value) !== '') {
                $content = $value;

                break;
            }
        }

        if ($content === null) {
            throw AiProviderException::malformedResponse(
                $this->key(),
                'no recognisable text field in response',
            );
        }

        $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];

        return new AiChatResponse(
            content: $content,
            toolCalls: [],
            finishReason: is_string($body['finish_reason'] ?? null) ? $body['finish_reason'] : null,
            tokensIn: $this->tokenCount($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null),
            tokensOut: $this->tokenCount($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null),
            model: is_string($body['model'] ?? null) && $body['model'] !== '' ? $body['model'] : $request->model,
            raw: $this->redactedRaw($body),
        );
    }

    /**
     * @param array<array-key, mixed> $body
     * @param list<string> $path
     */
    private function dig(array $body, array $path): mixed
    {
        $value = $body;

        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}
