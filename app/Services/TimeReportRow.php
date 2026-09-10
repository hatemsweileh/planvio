<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One grouped line of a time report.
 *
 * Minutes, never hours: `time_entries.minutes` is an integer for a reason, and a report
 * that rounds to hours per row and then sums the roundings does not reconcile with the
 * entries it claims to summarise. {@see hours()} exists for display and nothing else.
 */
final readonly class TimeReportRow
{
    public const DIMENSION_PROJECT = 'project';

    public const DIMENSION_USER = 'user';

    public const DIMENSION_TASK = 'task';

    public const DIMENSION_DAY = 'day';

    public function __construct(
        public string $dimension,
        public ?int $id,
        public ?string $label,
        public ?string $reference,
        public int $minutes,
        public int $billableMinutes,
        public int $entries,
    ) {}

    public function nonBillableMinutes(): int
    {
        return max(0, $this->minutes - $this->billableMinutes);
    }

    public function hours(): float
    {
        return round($this->minutes / 60, 2);
    }

    public function billableHours(): float
    {
        return round($this->billableMinutes / 60, 2);
    }

    /**
     * @param list<self> $rows
     */
    public static function totalMinutes(array $rows): int
    {
        $total = 0;

        foreach ($rows as $row) {
            $total += $row->minutes;
        }

        return $total;
    }

    /**
     * @return array{
     *     dimension: string,
     *     id: int|null,
     *     label: string|null,
     *     reference: string|null,
     *     minutes: int,
     *     billable_minutes: int,
     *     entries: int
     * }
     */
    public function toArray(): array
    {
        return [
            'dimension' => $this->dimension,
            'id' => $this->id,
            'label' => $this->label,
            'reference' => $this->reference,
            'minutes' => $this->minutes,
            'billable_minutes' => $this->billableMinutes,
            'entries' => $this->entries,
        ];
    }
}
