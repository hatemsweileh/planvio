<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * An inclusive span of whole days, used by every reporting service.
 *
 * Reports are asked for in days, never in instants: "this month" has to mean the same
 * thing whether the rows carry a `date` column (MySQL) or the full `Y-m-d H:i:s` string
 * Eloquent's date cast writes under SQLite. The range therefore carries plain dates and
 * exposes a half-open bound — `>= from` and `< toExclusive` — which is exact on both
 * drivers and still reads straight off the date indexes.
 */
final readonly class DateRange
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();

        if ($this->to->lessThan($this->from)) {
            throw new InvalidArgumentException(
                'A date range cannot end before it starts: '.$this->from->toDateString()
                .' to '.$this->to->toDateString().'.',
            );
        }
    }

    public static function of(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
        string $timezone = 'UTC',
    ): self {
        return new self(
            self::normaliseDay($from, $timezone),
            self::normaliseDay($to, $timezone),
        );
    }

    /**
     * The $days days ending today, today included — `lastDays(7)` is a rolling week.
     */
    public static function lastDays(int $days, string $timezone = 'UTC', ?DateTimeInterface $now = null): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A date range must span at least one day.');
        }

        $today = self::today($timezone, $now);

        return new self($today->subDays($days - 1), $today);
    }

    /**
     * The $days days starting today, today included.
     */
    public static function nextDays(int $days, string $timezone = 'UTC', ?DateTimeInterface $now = null): self
    {
        if ($days < 1) {
            throw new InvalidArgumentException('A date range must span at least one day.');
        }

        $today = self::today($timezone, $now);

        return new self($today, $today->addDays($days - 1));
    }

    public static function month(string $timezone = 'UTC', ?DateTimeInterface $now = null): self
    {
        $today = self::today($timezone, $now);

        return new self($today->startOfMonth(), $today->endOfMonth());
    }

    /**
     * @param int $weekStartsOn 0 = Sunday … 6 = Saturday, matching `workspaces.week_starts_on`
     */
    public static function week(string $timezone = 'UTC', int $weekStartsOn = 1, ?DateTimeInterface $now = null): self
    {
        $today = self::today($timezone, $now);

        return new self(
            $today->startOfWeek($weekStartsOn),
            $today->startOfWeek($weekStartsOn)->addDays(6),
        );
    }

    /**
     * Every day there has ever been, for callers that want an unbounded report without a
     * nullable range threaded through every signature.
     */
    public static function everything(): self
    {
        return new self(
            CarbonImmutable::create(1970, 1, 1, 0, 0, 0, 'UTC'),
            CarbonImmutable::create(2999, 12, 31, 0, 0, 0, 'UTC'),
        );
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /**
     * The exclusive upper bound: the day after the last included day.
     */
    public function toExclusiveDate(): string
    {
        return $this->to->addDay()->toDateString();
    }

    /**
     * Number of days covered, both ends included.
     */
    public function lengthInDays(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function contains(DateTimeInterface|string $date, string $timezone = 'UTC'): bool
    {
        $day = self::normaliseDay($date, $timezone);

        return $day->greaterThanOrEqualTo($this->from) && $day->lessThanOrEqualTo($this->to);
    }

    /**
     * @return array{from: string, to: string}
     */
    public function toArray(): array
    {
        return ['from' => $this->fromDate(), 'to' => $this->toDate()];
    }

    private static function today(string $timezone, ?DateTimeInterface $now): CarbonImmutable
    {
        $reference = $now === null
            ? CarbonImmutable::now($timezone)
            : CarbonImmutable::instance($now)->setTimezone($timezone);

        return $reference->startOfDay();
    }

    private static function normaliseDay(DateTimeInterface|string $value, string $timezone): CarbonImmutable
    {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)->setTimezone($timezone)
            : CarbonImmutable::parse($value, $timezone);

        return $date->startOfDay();
    }
}
