<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException as BaseDomainException;
use Throwable;

/**
 * A broken domain invariant, raised from inside an Action.
 *
 * Actions never authorize — callers do that — so anything thrown from one describes a rule
 * of the model itself: the last owner may not be demoted, a project key must be unique in
 * its workspace, a dependency may not close a cycle. Because every one of those is a normal,
 * expected outcome of a user request rather than a fault, the message is written to be shown
 * to the person who triggered it and is therefore always translated at the throw site.
 *
 * `context()` carries the machine-readable detail — ids, the offending value, the conflicting
 * record — for logs and for callers that want to render something richer than the sentence.
 * It must never carry a secret: it is surfaced in responses and written to logs.
 */
class DomainException extends BaseDomainException
{
    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $context
     */
    public function __construct(
        string $message,
        private readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param array<string, scalar|array<array-key, mixed>|null> $context
     */
    public static function make(string $message, array $context = []): static
    {
        return new static($message, $context);
    }

    /**
     * The message as written for the person who triggered the action.
     */
    public function userMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * @return array<string, scalar|array<array-key, mixed>|null>
     */
    public function context(): array
    {
        return $this->context;
    }
}
