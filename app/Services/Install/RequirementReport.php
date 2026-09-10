<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * The result of a pre-flight run: every row, plus the one question the wizard asks of it.
 */
final readonly class RequirementReport
{
    /**
     * @param list<Requirement> $requirements
     */
    public function __construct(public array $requirements) {}

    /**
     * Rows keyed by group, in the order the checker produced them.
     *
     * @return array<string, list<Requirement>>
     */
    public function groups(): array
    {
        $groups = [];

        foreach ($this->requirements as $requirement) {
            $groups[$requirement->group][] = $requirement;
        }

        return $groups;
    }

    /**
     * @return list<Requirement>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (Requirement $requirement): bool => $requirement->blocks(),
        ));
    }

    /**
     * @return list<Requirement>
     */
    public function warnings(): array
    {
        return array_values(array_filter(
            $this->requirements,
            static fn (Requirement $requirement): bool => ! $requirement->blocks()
                && $requirement->status !== RequirementStatus::Pass,
        ));
    }

    public function passes(): bool
    {
        return $this->blocking() === [];
    }

    /**
     * @return array{pass: int, warn: int, fail: int}
     */
    public function counts(): array
    {
        $counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];

        foreach ($this->requirements as $requirement) {
            $counts[$requirement->status->value]++;
        }

        return $counts;
    }
}
