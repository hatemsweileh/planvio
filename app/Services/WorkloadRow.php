<?php

declare(strict_types=1);

namespace App\Services;

/**
 * One person's slice of a workload report — or the unassigned pile, which is a workload
 * too and the one most often missing from tools that only count people.
 */
final readonly class WorkloadRow
{
    public function __construct(
        public ?int $userId,
        public ?string $userName,
        public int $total,
        public int $open,
        public int $completed,
        public int $overdue,
        public int $estimateMinutes,
    ) {}

    public static function empty(?int $userId, ?string $userName): self
    {
        return new self($userId, $userName, 0, 0, 0, 0, 0);
    }

    public function isUnassigned(): bool
    {
        return $this->userId === null;
    }

    public function hasWork(): bool
    {
        return $this->total > 0;
    }

    /**
     * Share of this row's open work that is already late, 0..1.
     */
    public function overdueShare(): float
    {
        return $this->open > 0 ? round($this->overdue / $this->open, 4) : 0.0;
    }

    public function estimateHours(): float
    {
        return round($this->estimateMinutes / 60, 2);
    }

    /**
     * @return array{
     *     user_id: int|null,
     *     user_name: string|null,
     *     total: int,
     *     open: int,
     *     completed: int,
     *     overdue: int,
     *     estimate_minutes: int
     * }
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'user_name' => $this->userName,
            'total' => $this->total,
            'open' => $this->open,
            'completed' => $this->completed,
            'overdue' => $this->overdue,
            'estimate_minutes' => $this->estimateMinutes,
        ];
    }
}
