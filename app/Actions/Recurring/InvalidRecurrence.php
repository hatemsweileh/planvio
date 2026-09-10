<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use DomainException;

/**
 * A repetition rule the scheduler could not honour.
 *
 * Named constructors rather than one free-text message: the generator runs unattended, so
 * `$rule` is what lets a log entry or a test say which invariant failed without matching on
 * a translated sentence.
 */
final class InvalidRecurrence extends DomainException
{
    private function __construct(
        public readonly string $rule,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function intervalTooSmall(int $interval): self
    {
        return new self('interval_too_small', __('actions.recurring.interval_too_small'));
    }

    public static function endsBeforeStart(): self
    {
        return new self('ends_before_start', __('actions.recurring.ends_before_start'));
    }

    public static function weekdayOutOfRange(): self
    {
        return new self('invalid_weekday', __('actions.recurring.invalid_weekday'));
    }

    public static function monthdayOutOfRange(): self
    {
        return new self('invalid_monthday', __('actions.recurring.invalid_monthday'));
    }

    public static function maxOccurrencesTooSmall(int $max): self
    {
        return new self('max_occurrences_too_small', __('actions.recurring.max_occurrences_too_small'));
    }

    public static function neverOccurs(): self
    {
        return new self('no_occurrence', __('actions.recurring.no_occurrence'));
    }

    public static function titleRequired(): self
    {
        return new self('title_required', __('actions.recurring.title_required'));
    }
}
