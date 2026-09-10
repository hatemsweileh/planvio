<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The counted result behind a `progress` percentage.
 *
 * The counts travel with the percentage on purpose: "60%" is unarguable when it is shown
 * as "3 of 5 tasks", and a progress bar that cannot say what it counted is a progress bar
 * nobody trusts.
 */
final readonly class ProjectProgress
{
    public function __construct(
        public int $subjectId,
        public int $total,
        public int $completed,
        public int $percentage,
    ) {}

    public static function empty(int $subjectId): self
    {
        return new self($subjectId, 0, 0, 0);
    }

    public static function fromCounts(int $subjectId, int $total, int $completed): self
    {
        return new self($subjectId, $total, $completed, self::percentage($total, $completed));
    }

    /**
     * Integer percent, 0..100. Rounded half-up, then clamped: 199 of 200 must not read as
     * "100%" — a bar at the end with work left is worse than one a percent short.
     */
    public static function percentage(int $total, int $completed): int
    {
        if ($total < 1) {
            return 0;
        }

        $percentage = (int) round($completed / $total * 100);

        if ($completed < $total && $percentage >= 100) {
            return 99;
        }

        return max(0, min(100, $percentage));
    }

    public function open(): int
    {
        return max(0, $this->total - $this->completed);
    }

    public function isComplete(): bool
    {
        return $this->total > 0 && $this->completed >= $this->total;
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    /**
     * @return array{id: int, total: int, completed: int, open: int, percentage: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->subjectId,
            'total' => $this->total,
            'completed' => $this->completed,
            'open' => $this->open(),
            'percentage' => $this->percentage,
        ];
    }
}
