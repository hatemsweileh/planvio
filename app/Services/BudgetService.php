<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Expense;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;

/**
 * Planned against actual against variance, per project (ARCHITECTURE.md §5.5).
 *
 *   planned   `projects.budget`, or null when nobody set one
 *   actual    the sum of `expenses.amount` booked to the project **in the project currency**
 *   variance  planned − actual
 *
 * Labour is deliberately not costed. There is no rate on `users`, `workspace_members` or
 * `projects` to cost it with, and inventing one — a workspace-wide average, say — would
 * produce a number that looks authoritative and is not. Logged minutes travel with the
 * result as context so a caller can show effort next to spend without implying a price.
 *
 * Currency is resolved per project: `projects.currency`, falling back to the workspace's.
 * Expenses booked in any other currency are reported under `unconverted` rather than added
 * to the total. Planvio ships no exchange rates, and a budget report that silently sums
 * across currencies is worse than one that admits it cannot.
 *
 * Three queries for any number of projects — expenses, time, and the workspace currencies —
 * and the third is skipped when every project names its own currency.
 */
final class BudgetService
{
    public function forProject(Project $project, ?DateRange $range = null): ProjectBudget
    {
        $id = (int) $project->getKey();

        return $this->forProjects([$project], $range)[$id];
    }

    /**
     * @param iterable<Project> $projects
     * @return array<int, ProjectBudget> keyed by project id
     */
    public function forProjects(iterable $projects, ?DateRange $range = null): array
    {
        /** @var array<int, Project> $indexed */
        $indexed = [];

        foreach ($projects as $project) {
            $indexed[(int) $project->getKey()] = $project;
        }

        if ($indexed === []) {
            return [];
        }

        $ids = array_keys($indexed);
        $currencies = $this->resolveCurrencies($indexed);
        $expenses = $this->expenseTotals($ids, $range);
        $time = $this->timeTotals($ids, $range);

        $budgets = [];

        foreach ($indexed as $id => $project) {
            $currency = $currencies[$id];
            $byCurrency = $expenses[$id] ?? [];

            $actual = $byCurrency[$currency]['minor'] ?? 0;
            $count = $byCurrency[$currency]['count'] ?? 0;

            $unconverted = [];

            foreach ($byCurrency as $code => $totals) {
                if ($code === $currency) {
                    continue;
                }

                $unconverted[$code] = $totals['minor'];
                $count += $totals['count'];
            }

            $budget = $project->budget;

            $budgets[$id] = new ProjectBudget(
                projectId: $id,
                currency: $currency,
                plannedMinor: $budget === null ? null : self::toMinor($budget),
                actualMinor: $actual,
                expenseCount: $count,
                unconverted: $unconverted,
                loggedMinutes: $time[$id]['minutes'] ?? 0,
                billableMinutes: $time[$id]['billable'] ?? 0,
            );
        }

        return $budgets;
    }

    /**
     * @param array<int, Project> $projects
     * @return array<int, string> project id => ISO currency code
     */
    private function resolveCurrencies(array $projects): array
    {
        $fallback = strtoupper((string) config('planvio.defaults.workspace.currency', 'USD'));

        /** @var array<int, string> $resolved */
        $resolved = [];

        /** @var array<int, true> $needWorkspace */
        $needWorkspace = [];

        foreach ($projects as $id => $project) {
            $own = $project->currency;

            if (is_string($own) && $own !== '') {
                $resolved[$id] = strtoupper($own);

                continue;
            }

            $workspace = $project->relationLoaded('workspace') ? $project->getRelation('workspace') : null;

            if ($workspace instanceof Workspace && is_string($workspace->currency) && $workspace->currency !== '') {
                $resolved[$id] = strtoupper($workspace->currency);

                continue;
            }

            $needWorkspace[(int) $project->workspace_id] = true;
        }

        if ($needWorkspace !== []) {
            $workspaceCurrencies = Workspace::query()
                ->whereIn('id', array_keys($needWorkspace))
                ->pluck('currency', 'id')
                ->all();

            foreach ($projects as $id => $project) {
                if (isset($resolved[$id])) {
                    continue;
                }

                $currency = $workspaceCurrencies[(int) $project->workspace_id] ?? null;

                $resolved[$id] = is_string($currency) && $currency !== ''
                    ? strtoupper($currency)
                    : $fallback;
            }
        }

        foreach ($projects as $id => $project) {
            $resolved[$id] ??= $fallback;
        }

        return $resolved;
    }

    /**
     * @param list<int> $projectIds
     * @return array<int, array<string, array{minor: int, count: int}>>
     */
    private function expenseTotals(array $projectIds, ?DateRange $range): array
    {
        $query = Expense::query()
            ->whereIn('expenses.project_id', $projectIds)
            ->groupBy('expenses.project_id', 'expenses.currency')
            ->addSelect(['expenses.project_id as project_id', 'expenses.currency as currency'])
            ->selectRaw('coalesce(sum(expenses.amount), 0) as total_amount')
            ->selectRaw('count(*) as entry_count');

        if ($range instanceof DateRange) {
            $query
                ->where('expenses.incurred_on', '>=', $range->fromDate())
                ->where('expenses.incurred_on', '<', $range->toExclusiveDate());
        }

        $totals = [];

        foreach ($query->toBase()->get() as $row) {
            $currency = strtoupper((string) $row->currency);

            $totals[(int) $row->project_id][$currency] = [
                'minor' => self::toMinor($row->total_amount),
                'count' => (int) $row->entry_count,
            ];
        }

        return $totals;
    }

    /**
     * @param list<int> $projectIds
     * @return array<int, array{minutes: int, billable: int}>
     */
    private function timeTotals(array $projectIds, ?DateRange $range): array
    {
        $query = TimeEntry::query()
            ->whereIn('time_entries.project_id', $projectIds)
            ->groupBy('time_entries.project_id')
            ->addSelect(['time_entries.project_id as project_id'])
            ->selectRaw('coalesce(sum(time_entries.minutes), 0) as total_minutes')
            ->selectRaw(
                'coalesce(sum(case when time_entries.is_billable = ? then time_entries.minutes else 0 end), 0)'
                .' as billable_minutes',
                [true],
            );

        if ($range instanceof DateRange) {
            $query
                ->where('time_entries.spent_on', '>=', $range->fromDate())
                ->where('time_entries.spent_on', '<', $range->toExclusiveDate());
        }

        $totals = [];

        foreach ($query->toBase()->get() as $row) {
            $totals[(int) $row->project_id] = [
                'minutes' => (int) $row->total_minutes,
                'billable' => (int) $row->billable_minutes,
            ];
        }

        return $totals;
    }

    /**
     * Decimal string or driver-returned number to whole minor units.
     *
     * MySQL hands back `decimal` as a string, SQLite as a float. The string path is parsed
     * digit by digit so nothing round-trips through binary floating point; the float path
     * rounds once, at a magnitude `decimal(15,2)` keeps well inside exact integer range.
     */
    private static function toMinor(mixed $amount): int
    {
        if ($amount === null) {
            return 0;
        }

        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_string($amount) && preg_match('/^\s*(-?)(\d+)(?:\.(\d*))?\s*$/', $amount, $matches) === 1) {
            $units = (int) $matches[2];
            $fraction = str_pad(mb_substr($matches[3] ?? '', 0, 2), 2, '0');
            $minor = $units * 100 + (int) $fraction;

            return $matches[1] === '-' ? -$minor : $minor;
        }

        return (int) round((float) $amount * 100);
    }
}
