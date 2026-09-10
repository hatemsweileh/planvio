<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Enums\RecurrenceFrequency;
use App\Models\RecurringTask;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * When a rule fires: the columns of `recurring_tasks` that describe repetition, gathered
 * into one value.
 *
 * Dates are normalised to midnight UTC. They are `date` columns, so the time of day is not
 * part of the meaning, and pinning the zone keeps day arithmetic exact — across a daylight
 * saving boundary a "day" in a local zone is 23 or 25 hours, and a schedule computed in one
 * would drift by a day twice a year.
 *
 * `byWeekday` is ISO numbering: 1 is Monday, 7 is Sunday.
 */
final readonly class RecurrenceSchedule
{
    /**
     * @param list<int> $byWeekday
     * @param list<int> $byMonthday
     */
    private function __construct(
        public RecurrenceFrequency $frequency,
        public CarbonImmutable $startsOn,
        public int $interval,
        public array $byWeekday,
        public array $byMonthday,
        public ?CarbonImmutable $endsOn,
        public ?int $maxOccurrences,
    ) {}

    /**
     * @param iterable<int, int|string> $byWeekday
     * @param iterable<int, int|string> $byMonthday
     */
    public static function make(
        RecurrenceFrequency $frequency,
        DateTimeInterface|string $startsOn,
        int $interval = 1,
        iterable $byWeekday = [],
        iterable $byMonthday = [],
        DateTimeInterface|string|null $endsOn = null,
        ?int $maxOccurrences = null,
    ): self {
        return new self(
            frequency: $frequency,
            startsOn: self::normalise($startsOn),
            interval: $interval,
            byWeekday: self::days($byWeekday, 1, 7),
            byMonthday: self::days($byMonthday, 1, 31),
            endsOn: $endsOn === null ? null : self::normalise($endsOn),
            maxOccurrences: $maxOccurrences,
        );
    }

    public static function fromModel(RecurringTask $rule): self
    {
        return self::make(
            frequency: $rule->frequency,
            startsOn: $rule->starts_on ?? CarbonImmutable::now(),
            interval: (int) $rule->interval,
            byWeekday: is_array($rule->by_weekday) ? $rule->by_weekday : [],
            byMonthday: is_array($rule->by_monthday) ? $rule->by_monthday : [],
            endsOn: $rule->ends_on,
            maxOccurrences: $rule->max_occurrences,
        );
    }

    /**
     * The columns this schedule writes onto `recurring_tasks`.
     *
     * Empty day lists are stored as null rather than `[]`: the column means "no restriction"
     * and null says that once, where an empty array invites a reader to treat it as
     * "restricted to nothing".
     *
     * @return array<string, mixed>
     */
    public function toColumns(): array
    {
        return [
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'by_weekday' => $this->byWeekday === [] ? null : $this->byWeekday,
            'by_monthday' => $this->byMonthday === [] ? null : $this->byMonthday,
            'starts_on' => $this->startsOn->toDateString(),
            'ends_on' => $this->endsOn?->toDateString(),
            'max_occurrences' => $this->maxOccurrences,
        ];
    }

    public static function normalise(DateTimeInterface|string $value): CarbonImmutable
    {
        $date = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);

        return CarbonImmutable::createFromFormat('Y-m-d', $date->format('Y-m-d'), 'UTC')->startOfDay();
    }

    /**
     * @param iterable<int, int|string> $values
     * @return list<int>
     */
    private static function days(iterable $values, int $min, int $max): array
    {
        $days = [];

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                throw InvalidRecurrence::weekdayOutOfRange();
            }

            $day = (int) $value;

            if ($day < $min || $day > $max) {
                throw $max === 7
                    ? InvalidRecurrence::weekdayOutOfRange()
                    : InvalidRecurrence::monthdayOutOfRange();
            }

            if (! in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        sort($days);

        return $days;
    }
}
