<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Planned against actual for one project, in that project's currency.
 *
 * Amounts are carried as integer minor units and formatted on the way out. `decimal(15,2)`
 * survives the database exactly and then meets PHP, where 0.1 + 0.2 is famously not 0.3;
 * a budget report that does its arithmetic in floats drifts by a cent per few thousand rows
 * and is impossible to reconcile against the expense list it summarises.
 *
 * `unconverted` is the honest half of the object. Expenses carry their own currency and
 * Planvio ships no exchange rates, so a cost booked in another currency is reported
 * separately rather than added in at an invented rate.
 */
final readonly class ProjectBudget
{
    /**
     * @param array<string, int> $unconverted currency code => minor units, excluding $currency
     */
    public function __construct(
        public int $projectId,
        public string $currency,
        public ?int $plannedMinor,
        public int $actualMinor,
        public int $expenseCount,
        public array $unconverted,
        public int $loggedMinutes,
        public int $billableMinutes,
    ) {}

    /**
     * Budget minus spend. Null when the project carries no budget: "0" would read as a
     * project exactly on plan, which is the opposite of "nobody set a plan".
     */
    public function varianceMinor(): ?int
    {
        return $this->plannedMinor === null ? null : $this->plannedMinor - $this->actualMinor;
    }

    /**
     * Share of the budget consumed, as a percentage. Null without a budget; unbounded
     * above 100 on purpose, because "142%" is the number someone needs to see.
     */
    public function utilisation(): ?float
    {
        if ($this->plannedMinor === null || $this->plannedMinor === 0) {
            return null;
        }

        return round($this->actualMinor / $this->plannedMinor * 100, 2);
    }

    public function isOverBudget(): bool
    {
        return $this->plannedMinor !== null && $this->actualMinor > $this->plannedMinor;
    }

    public function hasBudget(): bool
    {
        return $this->plannedMinor !== null;
    }

    public function hasUnconvertedCosts(): bool
    {
        return $this->unconverted !== [];
    }

    public function planned(): ?string
    {
        return $this->plannedMinor === null ? null : self::format($this->plannedMinor);
    }

    public function actual(): string
    {
        return self::format($this->actualMinor);
    }

    public function variance(): ?string
    {
        $variance = $this->varianceMinor();

        return $variance === null ? null : self::format($variance);
    }

    /**
     * @return array<string, string> currency code => formatted amount
     */
    public function unconvertedAmounts(): array
    {
        $amounts = [];

        foreach ($this->unconverted as $currency => $minor) {
            $amounts[$currency] = self::format($minor);
        }

        return $amounts;
    }

    /**
     * A decimal string with two places, never a float — the same shape the column stores.
     */
    public static function format(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $absolute = abs($minor);

        return $sign.intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * @return array{
     *     project_id: int,
     *     currency: string,
     *     planned: string|null,
     *     actual: string,
     *     variance: string|null,
     *     utilisation: float|null,
     *     expense_count: int,
     *     unconverted: array<string, string>,
     *     logged_minutes: int,
     *     billable_minutes: int
     * }
     */
    public function toArray(): array
    {
        return [
            'project_id' => $this->projectId,
            'currency' => $this->currency,
            'planned' => $this->planned(),
            'actual' => $this->actual(),
            'variance' => $this->variance(),
            'utilisation' => $this->utilisation(),
            'expense_count' => $this->expenseCount,
            'unconverted' => $this->unconvertedAmounts(),
            'logged_minutes' => $this->loggedMinutes,
            'billable_minutes' => $this->billableMinutes,
        ];
    }
}
