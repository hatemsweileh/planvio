<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProjectHealth;

/**
 * What {@see ProjectHealthCalculator} concluded about one project, and why.
 *
 * `reasons` is the point of the object. Each entry is a fact — a count, a date, a share —
 * carrying the code the UI translates and the severity it contributed. Nothing here is
 * prose: a health signal that says "the project is slipping" cannot be checked, argued
 * with, or re-derived a month later, whereas "7 of 21 open tasks are overdue, the oldest
 * since 2026-08-02" can.
 *
 * `health` is the value to display. It equals `computed` unless someone set the health by
 * hand, in which case `manual` is true and the stored value wins — the reasons are still
 * returned, so the UI can show what the calculator would have said.
 */
final readonly class ProjectHealthAssessment
{
    /**
     * @param list<array<string, mixed>> $reasons facts, worst severity first
     */
    public function __construct(
        public int $projectId,
        public ProjectHealth $health,
        public ProjectHealth $computed,
        public bool $manual,
        public array $reasons,
    ) {}

    public function isHealthy(): bool
    {
        return $this->health === ProjectHealth::OnTrack;
    }

    /**
     * Whether the stored value and the computed one disagree — the signal an admin screen
     * uses to surface "this project is pinned to on track but four milestones are late".
     */
    public function overridesComputed(): bool
    {
        return $this->manual && $this->health !== $this->computed;
    }

    /**
     * @return list<string>
     */
    public function reasonCodes(): array
    {
        return array_values(array_map(
            static fn (array $reason): string => (string) ($reason['code'] ?? ''),
            $this->reasons,
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reason(string $code): ?array
    {
        foreach ($this->reasons as $reason) {
            if (($reason['code'] ?? null) === $code) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     project_id: int,
     *     health: string,
     *     computed: string,
     *     manual: bool,
     *     reasons: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'health' => $this->health->value,
            'computed' => $this->computed->value,
            'manual' => $this->manual,
            'reasons' => $this->reasons,
        ];
    }
}
