<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who is carrying what, for a workspace or a single project.
 *
 * Two queries, always, whatever the size of the workspace: one grouped aggregate over
 * `tasks`, and one roster read so people holding nothing still appear. The roster query is
 * what makes the report answerable — a capacity view that only lists the busy is a view you
 * cannot reassign work from.
 *
 * A date range narrows on `due_date`, because that is the question capacity planning asks:
 * what is *due* in this window. Undated tasks are therefore absent from a ranged report and
 * present in an unranged one; `WorkloadService` never quietly folds them into a window they
 * were never scheduled into.
 *
 * "Overdue" is measured against the report's own `asOf` day, not the row's range, so a
 * report about next month still tells you what is late today.
 */
final class WorkloadService
{
    /**
     * @return list<WorkloadRow> busiest first
     */
    public function forWorkspace(
        Workspace $workspace,
        ?DateRange $range = null,
        ?DateTimeInterface $asOf = null,
    ): array {
        $counts = $this->counts(
            Task::query()->forWorkspace($workspace),
            $range,
            $asOf,
        );

        return $this->merge($counts, $this->workspaceRoster($workspace));
    }

    /**
     * @return list<WorkloadRow> busiest first
     */
    public function forProject(
        Project $project,
        ?DateRange $range = null,
        ?DateTimeInterface $asOf = null,
    ): array {
        $counts = $this->counts(
            Task::query()->where('tasks.project_id', $project->getKey()),
            $range,
            $asOf,
        );

        return $this->merge($counts, $this->projectRoster($project));
    }

    /**
     * The single busiest row, or null when nothing is assigned anywhere.
     *
     * @param list<WorkloadRow> $rows
     */
    public static function busiest(array $rows): ?WorkloadRow
    {
        foreach ($rows as $row) {
            if (! $row->isUnassigned() && $row->open > 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The aggregate. One row per distinct assignee, plus the unassigned bucket, computed
     * entirely in SQL — there is no per-member query anywhere in this service.
     *
     * @param Builder<Task> $query
     * @return array<int|string, array{total: int, open: int, completed: int, overdue: int, estimate: int}>
     */
    private function counts(
        Builder $query,
        ?DateRange $range,
        ?DateTimeInterface $asOf,
    ): array {
        $today = ($asOf === null ? CarbonImmutable::now() : CarbonImmutable::instance($asOf))->toDateString();

        if ($range instanceof DateRange) {
            // Half-open bounds rather than BETWEEN: MySQL stores `date` bare while SQLite
            // keeps the cast's "Y-m-d H:i:s", so an inclusive upper bound would drop the
            // last day of the range under SQLite.
            $query
                ->whereNotNull('tasks.due_date')
                ->where('tasks.due_date', '>=', $range->fromDate())
                ->where('tasks.due_date', '<', $range->toExclusiveDate());
        }

        $rows = $query
            ->groupBy('tasks.assignee_id')
            ->selectRaw('tasks.assignee_id as assignee_id')
            ->selectRaw('count(*) as total_tasks')
            ->selectRaw('sum(case when tasks.completed_at is null then 1 else 0 end) as open_tasks')
            ->selectRaw('sum(case when tasks.completed_at is not null then 1 else 0 end) as completed_tasks')
            ->selectRaw(
                'sum(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date < ? then 1 else 0 end) as overdue_tasks',
                [$today],
            )
            ->selectRaw('coalesce(sum(tasks.estimate_minutes), 0) as estimate_minutes')
            ->toBase()
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $key = $row->assignee_id === null ? 'unassigned' : (int) $row->assignee_id;

            $counts[$key] = [
                'total' => (int) $row->total_tasks,
                'open' => (int) $row->open_tasks,
                'completed' => (int) $row->completed_tasks,
                'overdue' => (int) $row->overdue_tasks,
                'estimate' => (int) $row->estimate_minutes,
            ];
        }

        return $counts;
    }

    /**
     * @return array<int, string> user id => name
     */
    private function workspaceRoster(Workspace $workspace): array
    {
        $rows = WorkspaceMember::query()
            ->forWorkspace($workspace)
            ->join('users', 'users.id', '=', 'workspace_members.user_id')
            ->whereNull('users.deleted_at')
            ->selectRaw('workspace_members.user_id as user_id, users.name as name')
            ->toBase()
            ->get();

        return $this->roster($rows);
    }

    /**
     * @return array<int, string> user id => name
     */
    private function projectRoster(Project $project): array
    {
        $rows = ProjectMember::query()
            ->where('project_members.project_id', $project->getKey())
            ->join('users', 'users.id', '=', 'project_members.user_id')
            ->whereNull('users.deleted_at')
            ->selectRaw('project_members.user_id as user_id, users.name as name')
            ->toBase()
            ->get();

        return $this->roster($rows);
    }

    /**
     * @param Collection<int, object> $rows
     * @return array<int, string>
     */
    private function roster(Collection $rows): array
    {
        $roster = [];

        foreach ($rows as $row) {
            $roster[(int) $row->user_id] = (string) $row->name;
        }

        return $roster;
    }

    /**
     * @param array<int|string, array{total: int, open: int, completed: int, overdue: int, estimate: int}> $counts
     * @param array<int, string> $roster
     * @return list<WorkloadRow>
     */
    private function merge(array $counts, array $roster): array
    {
        $rows = [];

        foreach ($roster as $userId => $name) {
            $count = $counts[$userId] ?? null;

            $rows[] = $count === null
                ? WorkloadRow::empty($userId, $name)
                : new WorkloadRow(
                    $userId,
                    $name,
                    $count['total'],
                    $count['open'],
                    $count['completed'],
                    $count['overdue'],
                    $count['estimate'],
                );
        }

        // Someone can hold tasks without holding a membership any more — a former member
        // whose work was never reassigned. Dropping them would make the totals lie.
        foreach ($counts as $key => $count) {
            if ($key === 'unassigned' || isset($roster[$key])) {
                continue;
            }

            $rows[] = new WorkloadRow(
                (int) $key,
                null,
                $count['total'],
                $count['open'],
                $count['completed'],
                $count['overdue'],
                $count['estimate'],
            );
        }

        if (isset($counts['unassigned'])) {
            $unassigned = $counts['unassigned'];

            $rows[] = new WorkloadRow(
                null,
                null,
                $unassigned['total'],
                $unassigned['open'],
                $unassigned['completed'],
                $unassigned['overdue'],
                $unassigned['estimate'],
            );
        }

        usort($rows, static function (WorkloadRow $a, WorkloadRow $b): int {
            return [$b->open, $b->overdue, $b->total] <=> [$a->open, $a->overdue, $a->total]
                ?: strcasecmp($a->userName ?? "\u{FFFF}", $b->userName ?? "\u{FFFF}");
        });

        return $rows;
    }
}
