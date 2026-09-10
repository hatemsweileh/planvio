<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Agent\ToolRegistry;

/**
 * One tool invocation proposed by the model, normalised across providers.
 *
 * This is transport-shaped, not authorised: the name is whatever the model emitted and the
 * arguments are whatever it produced. Nothing here has been checked. The agent layer resolves
 * the name through the fixed {@see ToolRegistry} — a name the registry does not
 * know is rejected outright and is never turned into a class name (AI_SECURITY, tool
 * authorization step 1).
 *
 * OpenAI-shaped providers hand back arguments as a JSON *string*, Anthropic as a decoded
 * object. {@see self::fromJson()} exists for the former and records a decode failure rather
 * than guessing: a malformed argument payload must fail schema validation, not silently
 * become an empty argument list that a tool might treat as "use the defaults".
 */
final readonly class ToolCall
{
    /**
     * @param array<string, mixed> $arguments decoded arguments; empty when malformed
     * @param string|null $rawArguments the provider's original payload, kept for the audit trail
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments = [],
        public ?string $rawArguments = null,
        public bool $malformed = false,
    ) {}

    /**
     * Build from an OpenAI-style `function.arguments` JSON string.
     */
    public static function fromJson(string $id, string $name, ?string $json): self
    {
        if ($json === null || trim($json) === '') {
            // An argument-less call is legitimate for a tool whose schema has no required
            // properties, so an empty payload is not a decode failure.
            return new self($id, $name, [], $json, false);
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            return new self($id, $name, [], $json, true);
        }

        /** @var array<string, mixed> $decoded */
        return new self($id, $name, $decoded, $json, false);
    }

    /**
     * The arguments as a JSON string, for providers that expect one when the call is
     * replayed back into the conversation.
     */
    public function argumentsJson(): string
    {
        if ($this->rawArguments !== null) {
            return $this->rawArguments;
        }

        $encoded = json_encode($this->arguments, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '{}' : $encoded;
    }

    public function isUsable(): bool
    {
        return $this->id !== '' && $this->name !== '' && ! $this->malformed;
    }
}
