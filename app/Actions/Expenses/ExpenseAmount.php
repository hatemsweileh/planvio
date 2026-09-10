<?php

declare(strict_types=1);

namespace App\Actions\Expenses;

use App\Exceptions\InvalidExpense;

/**
 * Normalises and bounds an amount before it reaches `expenses.amount`.
 *
 * Money never passes through a float here. `decimal(15,2)` is exact and PHP's float is not:
 * a value that round-trips through binary floating point stops adding up, and a budget that
 * does not add up is worse than one that refuses the input. Everything is done as a string
 * through bcmath, which the installer already requires.
 *
 * Only a plain decimal is accepted. Grouping separators are ambiguous across locales —
 * `1.234,56` and `1,234.56` are the same money written two ways and the wrong guess is off
 * by a thousand — so the input is refused rather than interpreted.
 */
final readonly class ExpenseAmount
{
    private function __construct(public string $value) {}

    public static function from(string|int|float $amount): self
    {
        $raw = trim((string) $amount);

        // A leading currency symbol and any spacing are cosmetic; everything else has to be
        // a decimal already.
        $raw = (string) preg_replace('/^[+\p{Sc}\s\x{00A0}]+/u', '', $raw);
        $raw = (string) preg_replace('/[\s\x{00A0}]+/u', '', $raw);

        if (preg_match('/^-?\d{1,16}(\.\d{1,6})?$/', $raw) !== 1) {
            throw InvalidExpense::amountNotPositive((string) $amount);
        }

        $scaled = self::toTwoPlaces($raw);

        if (bccomp($scaled, '0.00', 2) !== 1) {
            throw InvalidExpense::amountNotPositive((string) $amount);
        }

        if (bccomp($scaled, InvalidExpense::MAX_AMOUNT, 2) === 1) {
            throw InvalidExpense::amountTooLarge((string) $amount);
        }

        return new self($scaled);
    }

    /**
     * Round half up at two places, the way an invoice does. bcmath truncates, so the
     * rounding is done by hand rather than left to it.
     */
    private static function toTwoPlaces(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');

        $rounded = bcadd($absolute, '0.005', 3);
        $rounded = bcadd($rounded, '0', 2);

        return $negative ? '-'.$rounded : $rounded;
    }

    /**
     * ISO 4217 is three letters. The column is a `char(3)`, so anything else would be
     * truncated by the database if it were not caught here.
     */
    public static function currency(string $currency): string
    {
        $code = strtoupper(trim($currency));

        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw InvalidExpense::invalidCurrency($currency);
        }

        return $code;
    }
}
