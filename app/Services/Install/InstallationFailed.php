<?php

declare(strict_types=1);

namespace App\Services\Install;

use RuntimeException;
use Throwable;

/**
 * A step that could not complete, in the shape spec §140 asks for.
 *
 * Four fields, all safe to render: which step, why in plain language, what to do about it,
 * and a reference id that matches a line in `storage/logs`. The originating exception is
 * carried as `previous` so the framework's own handler still logs a full trace where the
 * administrator cannot see it — but nothing in the message, the suggestion or the checkpoint
 * file is ever derived from it.
 */
final class InstallationFailed extends RuntimeException
{
    public function __construct(
        public readonly InstallStep $step,
        public readonly string $reason,
        public readonly string $suggestion,
        public readonly string $reference,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }

    /**
     * The record written to the checkpoint file and read back by the failure screen.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'step' => $this->step->value,
            'label' => $this->step->label(),
            'reason' => $this->reason,
            'suggestion' => $this->suggestion,
            'reference' => $this->reference,
            'failed_at' => now()->toIso8601String(),
        ];
    }
}
