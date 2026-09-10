<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Completion percentages for projects and milestones (ARCHITECTURE.md §5.2).
 *
 * The v1 definition is deliberately the simplest one that can be defended out loud:
 *
 *   progress = completed tasks / total tasks
 *
 * with no weighting by estimate, priority, subtask depth or milestone. Weighted progress
 * is more accurate in principle and unexplainable in practice — when someone asks why the
 * bar moved backwards after an estimate was edited, there is no good answer. A task counts
 * as completed when its status carries `is_completed`, which is what `Project::completedTaskCount`
 * already means, so "Cancelled" columns count as resolved. Subtasks count as tasks.
 *
 * `projects.progress` and `milestones.progress` are denormalised caches of this number.
 * Recalculation writes them without touching `updated_at`: the cache being refreshed is
 * not a change to the project, and bumping the timestamp would reorder every "recently
 * modified" list every time a task closed.
 */
final class ProjectProgressCalculator
{
    /**
     * Ids per UPDATE statement. Keeps the generated CASE expression inside every engine's
     * statement limits without giving up the single-query rewrite.
     */
    private const UPDATE_CHUNK = 500;

    public function forProject(Project|int $project): ProjectProgress
    {
        $id = self::idOf($project);

        return $this->forProjects([$id])[$id] ?? ProjectProgress::empty($id);
    }

    public function forMilestone(Milestone|int $milestone): ProjectProgress
    {
        $id = self::idOf($milestone);

        return $this->forMilestones([$id])[$id] ?? ProjectProgress::empty($id);
    }

    /**
     * One aggregate query for any number of projects.
     *
     * @param iterable<Project|int> $projects
     * @return array<int, ProjectProgress> keyed by project id, one entry per input
     */
    public function forProjects(iterable $projects): array
    {
        return $this->aggregate('project_id', $projects);
    }

    /**
     * @param iterable<Milestone|int> $milestones
     * @return array<int, ProjectProgress> keyed by milestone id, one entry per input
     */
    public function forMilestones(iterable $milestones): array
    {
        return $this->aggregate('milestone_id', $milestones);
    }

    /**
     * Recompute and store `projects.progress`.
     */
    public function recalculate(Project|int $project): int
    {
        $id = self::idOf($project);

        return $this->recalculateMany([$id])[$id] ?? 0;
    }

    /**
     * Recompute and store `projects.progress` for many projects.
     *
     * Two queries regardless of how many projects are passed: one grouped aggregate over
     * `tasks`, then one UPDATE per chunk carrying a CASE expression.
     *
     * @param iterable<Project|int> $projects
     * @return array<int, int> the stored percentage keyed by project id
     */
    public function recalculateMany(iterable $projects): array
    {
        $progress = $this->forProjects($projects);

        $percentages = [];

        foreach ($progress as $id => $result) {
            $percentages[$id] = $result->percentage;
        }

        $this->store(Project::query(), $percentages);

        return $percentages;
    }

    /**
     * Recompute and store `milestones.progress`.
     *
     * @param iterable<Milestone|int> $milestones
     * @return array<int, int> the stored percentage keyed by milestone id
     */
    public function recalculateMilestones(iterable $milestones): array
    {
        $progress = $this->forMilestones($milestones);

        $percentages = [];

        foreach ($progress as $id => $result) {
            $percentages[$id] = $result->percentage;
        }

        $this->store(Milestone::query(), $percentages);

        return $percentages;
    }

    /**
     * Every milestone of a project, refreshed together — what a milestone-aware task move
     * needs, and still only two queries.
     *
     * @return array<int, int>
     */
    public function recalculateMilestonesOfProject(Project|int $project): array
    {
        $ids = Milestone::query()
            ->where('project_id', self::idOf($project))
            ->pluck('id')
            ->all();

        return $ids === [] ? [] : $this->recalculateMilestones(array_map(intval(...), $ids));
    }

    /**
     * @param 'project_id'|'milestone_id' $column
     * @param iterable<Project|Milestone|int> $subjects
     * @return array<int, ProjectProgress>
     */
    private function aggregate(string $column, iterable $subjects): array
    {
        $ids = self::identifiers($subjects);

        if ($ids === []) {
            return [];
        }

        // Every id gets an entry, so a project with no tasks reads as 0% instead of
        // vanishing from the result and leaving a stale cached value behind.
        $results = [];

        foreach ($ids as $id) {
            $results[$id] = ProjectProgress::empty($id);
        }

        // Eloquent, not the query builder: the workspace scope and the soft-delete scope
        // are part of what makes this correct, and toBase() applies both before the join.
        $rows = Task::query()
            ->join('task_statuses', 'task_statuses.id', '=', 'tasks.status_id')
            ->whereIn('tasks.'.$column, $ids)
            ->groupBy('tasks.'.$column)
            ->selectRaw('tasks.'.$column.' as subject_id')
            ->selectRaw('count(*) as total_tasks')
            ->selectRaw('sum(case when task_statuses.is_completed = ? then 1 else 0 end) as completed_tasks', [true])
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $id = (int) $row->subject_id;

            $results[$id] = ProjectProgress::fromCounts(
                $id,
                (int) $row->total_tasks,
                (int) $row->completed_tasks,
            );
        }

        return $results;
    }

    /**
     * Write many percentages with one statement per chunk.
     *
     * The CASE arms are interpolated rather than bound. Both halves of every arm are
     * integers this class produced — row ids read back from the database and percentages
     * clamped to 0..100 — and they are cast again here, so there is no string reaching the
     * expression and no injection surface. Binding them instead would mean hand-building
     * the UPDATE and losing the global scopes that `toBase()` applies.
     *
     * @param Builder<Project>|Builder<Milestone> $query
     * @param array<int, int> $percentages
     */
    private function store(Builder $query, array $percentages): void
    {
        if ($percentages === []) {
            return;
        }

        foreach (array_chunk($percentages, self::UPDATE_CHUNK, true) as $chunk) {
            $arms = '';

            foreach ($chunk as $id => $percentage) {
                $arms .= ' when '.(int) $id.' then '.max(0, min(100, (int) $percentage));
            }

            (clone $query)
                ->whereIn('id', array_keys($chunk))
                ->toBase()
                ->update(['progress' => DB::raw('case id'.$arms.' else progress end')]);
        }
    }

    /**
     * @param iterable<Project|Milestone|int> $subjects
     * @return list<int>
     */
    private static function identifiers(iterable $subjects): array
    {
        $ids = [];

        foreach ($subjects as $subject) {
            $ids[self::idOf($subject)] = true;
        }

        return array_map(intval(...), array_keys($ids));
    }

    private static function idOf(Project|Milestone|int $subject): int
    {
        return $subject instanceof Project || $subject instanceof Milestone
            ? (int) $subject->getKey()
            : $subject;
    }
}
