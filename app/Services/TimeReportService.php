<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Logged minutes, grouped four ways, over a range (ARCHITECTURE.md §5.5).
 *
 * Every method is exactly one grouped query: the grouping, the billable split and the
 * entry count are all computed in SQL, and labels come from a join rather than a second
 * pass over the rows. Nothing here loads a model.
 *
 * The scope is always passed in — a `Workspace` or a `Project` — rather than inferred from
 * whatever happens to be bound. The tenant scope on `TimeEntry` still applies underneath;
 * the explicit constraint is the second of the two layers §3 requires, and it is what makes
 * these methods safe to call from a queued job where nothing is bound at all.
 */
final class TimeReportService
{
    /**
     * A `date` column reads back bare on MySQL and as the cast's "Y-m-d H:i:s" on SQLite.
     * Cutting the first ten characters gives one key shape on both, and both engines will
     * group by the expression.
     */
    private const DAY_EXPRESSION = 'substr(time_entries.spent_on, 1, 10)';

    /**
     * @return list<TimeReportRow> most minutes first
     */
    public function byProject(Workspace|Project $scope, DateRange $range, ?User $user = null): array
    {
        $rows = $this->base($scope, $range, $user)
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->groupBy('time_entries.project_id', 'projects.name', 'projects.key')
            ->addSelect([
                'time_entries.project_id as group_id',
                'projects.name as label',
                // Selected through the grammar rather than selectRaw: `key` is a reserved
                // word in MySQL and has to reach the server quoted.
                'projects.key as reference',
            ])
            ->selectRaw($this->minutesExpression())
            ->selectRaw($this->billableExpression(), [true])
            ->selectRaw('count(*) as entry_count')
            ->toBase()
            ->get();

        return $this->rows($rows, TimeReportRow::DIMENSION_PROJECT);
    }

    /**
     * @return list<TimeReportRow> most minutes first
     */
    public function byUser(Workspace|Project $scope, DateRange $range): array
    {
        $rows = $this->base($scope, $range, null)
            ->join('users', 'users.id', '=', 'time_entries.user_id')
            ->groupBy('time_entries.user_id', 'users.name', 'users.email')
            ->addSelect([
                'time_entries.user_id as group_id',
                'users.name as label',
                'users.email as reference',
            ])
            ->selectRaw($this->minutesExpression())
            ->selectRaw($this->billableExpression(), [true])
            ->selectRaw('count(*) as entry_count')
            ->toBase()
            ->get();

        return $this->rows($rows, TimeReportRow::DIMENSION_USER);
    }

    /**
     * Time booked against each task, plus one row for time booked at project level.
     *
     * `time_entries.task_id` is nullable and that null is meaningful — it is the hours
     * someone spent on the project without a task to hang them on. It comes back as a row
     * with a null id rather than being dropped, so the parts still sum to the whole.
     *
     * @return list<TimeReportRow> most minutes first
     */
    public function byTask(Workspace|Project $scope, DateRange $range, ?User $user = null): array
    {
        $rows = $this->base($scope, $range, $user)
            ->leftJoin('tasks', 'tasks.id', '=', 'time_entries.task_id')
            ->leftJoin('projects', 'projects.id', '=', 'tasks.project_id')
            ->groupBy('time_entries.task_id', 'tasks.title', 'tasks.number', 'projects.key')
            ->addSelect([
                'time_entries.task_id as group_id',
                'tasks.title as label',
                'projects.key as project_key',
                'tasks.number as task_number',
            ])
            ->selectRaw($this->minutesExpression())
            ->selectRaw($this->billableExpression(), [true])
            ->selectRaw('count(*) as entry_count')
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $key = $row->project_key === null || $row->task_number === null
                ? null
                : $row->project_key.'-'.$row->task_number;

            $result[] = new TimeReportRow(
                dimension: TimeReportRow::DIMENSION_TASK,
                id: $row->group_id === null ? null : (int) $row->group_id,
                label: $row->label === null ? null : (string) $row->label,
                reference: $key,
                minutes: (int) $row->total_minutes,
                billableMinutes: (int) $row->billable_minutes,
                entries: (int) $row->entry_count,
            );
        }

        usort($result, static fn (TimeReportRow $a, TimeReportRow $b): int => $b->minutes <=> $a->minutes);

        return $result;
    }

    /**
     * @return list<TimeReportRow> chronological
     */
    public function byDay(Workspace|Project $scope, DateRange $range, ?User $user = null): array
    {
        $rows = $this->base($scope, $range, $user)
            ->groupByRaw(self::DAY_EXPRESSION)
            ->selectRaw(self::DAY_EXPRESSION.' as label')
            ->selectRaw($this->minutesExpression())
            ->selectRaw($this->billableExpression(), [true])
            ->selectRaw('count(*) as entry_count')
            ->orderByRaw(self::DAY_EXPRESSION)
            ->toBase()
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[] = new TimeReportRow(
                dimension: TimeReportRow::DIMENSION_DAY,
                id: null,
                label: (string) $row->label,
                reference: null,
                minutes: (int) $row->total_minutes,
                billableMinutes: (int) $row->billable_minutes,
                entries: (int) $row->entry_count,
            );
        }

        return $result;
    }

    public function totalMinutes(Workspace|Project $scope, DateRange $range, ?User $user = null): int
    {
        return (int) $this->base($scope, $range, $user)->sum('time_entries.minutes');
    }

    public function billableMinutes(Workspace|Project $scope, DateRange $range, ?User $user = null): int
    {
        return (int) $this->base($scope, $range, $user)
            ->where('time_entries.is_billable', true)
            ->sum('time_entries.minutes');
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function base(Workspace|Project $scope, DateRange $range, ?User $user): Builder
    {
        $query = TimeEntry::query();

        if ($scope instanceof Workspace) {
            $query->forWorkspace($scope);
        } else {
            // Narrow by workspace as well as project: two independent constraints, so a
            // project id that somehow belongs to another tenant still returns nothing.
            $query
                ->forWorkspace((int) $scope->workspace_id)
                ->where('time_entries.project_id', $scope->getKey());
        }

        if ($user instanceof User) {
            $query->where('time_entries.user_id', $user->getKey());
        }

        // Half-open bounds: exact whether `spent_on` came back as a bare date or as the
        // date cast's "Y-m-d H:i:s", and still readable off index(project_id, spent_on).
        return $query
            ->where('time_entries.spent_on', '>=', $range->fromDate())
            ->where('time_entries.spent_on', '<', $range->toExclusiveDate());
    }

    private function minutesExpression(): string
    {
        return 'coalesce(sum(time_entries.minutes), 0) as total_minutes';
    }

    private function billableExpression(): string
    {
        return 'coalesce(sum(case when time_entries.is_billable = ? then time_entries.minutes else 0 end), 0)'
            .' as billable_minutes';
    }

    /**
     * @param Collection<int, object> $rows
     * @return list<TimeReportRow>
     */
    private function rows(Collection $rows, string $dimension): array
    {
        $result = [];

        foreach ($rows as $row) {
            $result[] = new TimeReportRow(
                dimension: $dimension,
                id: $row->group_id === null ? null : (int) $row->group_id,
                label: $row->label === null ? null : (string) $row->label,
                reference: $row->reference === null ? null : (string) $row->reference,
                minutes: (int) $row->total_minutes,
                billableMinutes: (int) $row->billable_minutes,
                entries: (int) $row->entry_count,
            );
        }

        usort($result, static fn (TimeReportRow $a, TimeReportRow $b): int => $b->minutes <=> $a->minutes);

        return $result;
    }
}
