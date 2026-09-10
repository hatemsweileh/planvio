<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * An expense cannot be written as asked.
 *
 * Money is `decimal(15,2)` (ARCHITECTURE.md §5), so the bounds here are the column's, not
 * an arbitrary policy: a value the column cannot hold would be silently truncated by MySQL
 * and quietly wrong in every budget total afterwards.
 */
final class InvalidExpense extends DomainException
{
    /** decimal(15,2) holds up to 13 digits before the decimal point. */
    public const MAX_AMOUNT = '9999999999999.99';

    public static function amountNotPositive(string $amount): self
    {
        return new self(
            __('actions.expenses.amount_not_positive'),
            ['amount' => $amount],
        );
    }

    public static function amountTooLarge(string $amount): self
    {
        return new self(
            __('actions.expenses.amount_too_large'),
            ['amount' => $amount, 'maximum' => self::MAX_AMOUNT],
        );
    }

    public static function invalidCurrency(string $currency): self
    {
        return new self(
            __('actions.expenses.invalid_currency'),
            ['currency' => $currency],
        );
    }

    public static function futureDate(string $date): self
    {
        return new self(
            __('actions.expenses.future_date'),
            ['incurred_on' => $date],
        );
    }
}
