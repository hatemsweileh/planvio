<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use DomainException;

/**
 * A value that the columns would accept but the domain would not: a due date before the
 * start date, progress outside 0..100, a negative estimate.
 *
 * Named constructors rather than a bare message so each call site states which rule it is
 * enforcing, and so the caller can tell the cases apart from `$rule`.
 */
final class InvalidTaskAttributes extends DomainException
{
    private function __construct(
        public readonly string $rule,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function dueBeforeStart(): self
    {
        return new self('due_before_start', __('actions.tasks.due_before_start'));
    }

    public static function progressOutOfRange(int $progress): self
    {
        return new self('progress_out_of_range', __('actions.tasks.progress_out_of_range'));
    }

    public static function negativeEstimate(int $minutes): self
    {
        return new self('estimate_negative', __('actions.tasks.estimate_negative'));
    }
}
