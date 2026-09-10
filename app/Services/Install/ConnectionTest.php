<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * The answer to one of the wizard's three "Test …" buttons.
 *
 * `message` is always a finished sentence written for the person installing Planvio. It never
 * carries a driver string, a stack trace or anything that came back from the far end
 * verbatim: the moment a database or an SMTP server rejects a credential is exactly the
 * moment it is most likely to quote that credential back (CLAUDE.md rule 4).
 *
 * `reference` is set only when something happened that the message could not fully describe.
 * It matches a line in the application log, so support can ask for six characters instead of
 * asking somebody to paste an exception.
 */
final readonly class ConnectionTest
{
    public function __construct(
        public RequirementStatus $status,
        public string $message,
        public ?string $detail = null,
        public ?string $reference = null,
    ) {}

    public static function passed(string $message, ?string $detail = null): self
    {
        return new self(RequirementStatus::Pass, $message, $detail);
    }

    public static function warned(string $message, ?string $detail = null): self
    {
        return new self(RequirementStatus::Warn, $message, $detail);
    }

    public static function failed(string $message, ?string $detail = null, ?string $reference = null): self
    {
        return new self(RequirementStatus::Fail, $message, $detail, $reference);
    }

    /**
     * A warning is a pass with a caveat: the connection worked.
     */
    public function ok(): bool
    {
        return ! $this->status->isFailure();
    }

    /**
     * Livewire keeps component state in the request payload, and only primitives survive that
     * round trip intact, so the screens hold the result as an array rather than as this
     * object — and the partial that renders it reads the same four keys.
     *
     * @return array{status: string, message: string, detail: string|null, reference: string|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'detail' => $this->detail,
            'reference' => $this->reference,
        ];
    }
}
