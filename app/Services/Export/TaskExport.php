<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Every task the reader may see, narrowed by the filters they are looking at.
 *
 * ## The predicate
 *
 * The filters are the ones the task list puts in the query string, and they are applied
 * here through the same model scopes the list uses — `overdue()`, `dueBetween()` — rather
 * than by hand-written date arithmetic, so "overdue" cannot come to mean two things. What
 * this class adds beyond the list is the reach: the list is one project, an export may be
 * the whole workspace, so the row set is additionally bounded by
 * {@see Project::scopeVisibleTo()}. A guest exports the projects they were added to and
 * nothing else.
 *
 * ## Subtasks
 *
 * The list hides subtasks under their parents; the export includes them, each carrying its
 * parent's key. A spreadsheet has no disclosure triangle, and an export that silently
 * dropped half the work would be worse than one with an extra column.
 */
final class TaskExport implements CsvExport
{
    /** @var list<int>|null */
    private ?array $projectIds = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $reader,
        private readonly ExportFilters $filters,
    ) {}

    public function subject(): string
    {
        return 'tasks';
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            __('Key'),
            __('Title'),
            __('Description'),
            __('Project'),
            __('Project key'),
            __('Status'),
            __('Status category'),
            __('Priority'),
            __('Assignee'),
            __('Assignee email'),
            __('Reporter'),
            __('Milestone'),
            __('Parent'),
            __('Start date'),
            __('Due date'),
            __('Completed at'),
            __('Estimate (minutes)'),
            __('Estimate (hours)'),
            __('Progress %'),
            __('Tags'),
            __('Created at'),
            __('Updated at'),
        ];
    }

    public function total(): int
    {
        return $this->query($this->visibleProjectIds())->count();
    }

    /**
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(): Generator
    {
        // One project at a time, and inside it a keyset walk on the primary key.
        //
        // `lazyById` is the only chunking that stays correct and cheap on a large table —
        // it seeks past the last id rather than counting rows off an OFFSET — but it owns
        // the ORDER BY, so the readable grouping has to come from the outer loop. Tasks are
        // numbered in creation order, so id order inside a project is number order.
        foreach ($this->visibleProjectIds() as $projectId) {
            $query = $this->query([$projectId])->with([
                'project:id,workspace_id,name,key,slug',
                'status:id,name,category,is_completed',
                'assignee:id,name,email',
                'reporter:id,name',
                'milestone:id,name',
                'parent:id,number,project_id',
                'parent.project:id,key',
                'tags:id,name',
            ]);

            // The eager loads above are re-applied to every chunk, which is what
            // `preventLazyLoading` demands of a mapper that touches seven relations.
            foreach ($query->lazyById(500, 'tasks.id') as $task) {
                yield $this->row($task);
            }
        }
    }

    /**
     * @return list<scalar|null>
     */
    private function row(Task $task): array
    {
        $estimate = $task->estimate_minutes;

        return [
            $task->key,
            $task->title,
            $task->description,
            $task->project?->name,
            $task->project?->key,
            $task->status?->name,
            $task->status?->category?->label(),
            $task->priority->label(),
            $task->assignee?->name,
            $task->assignee?->email,
            $task->reporter?->name,
            $task->milestone?->name,
            $task->parent?->key,
            $task->start_date?->format('Y-m-d'),
            $task->due_date?->format('Y-m-d'),
            $task->completed_at?->format('Y-m-d H:i'),
            $estimate,
            $estimate === null ? null : round($estimate / 60, 2),
            $task->progress,
            $task->tags->pluck('name')->implode(', '),
            $task->created_at?->format('Y-m-d H:i'),
            $task->updated_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @param list<int> $projectIds
     * @return Builder<Task>
     */
    private function query(array $projectIds): Builder
    {
        $filters = $this->filters;

        $query = Task::query()
            ->forWorkspace($this->workspace)
            ->whereIn('tasks.project_id', $projectIds);

        $term = trim($filters->search);

        if ($term !== '') {
            $query->where(function (Builder $matches) use ($term): void {
                $matches->where('tasks.title', 'like', '%'.$term.'%');

                if (preg_match('/(\d+)\s*$/', $term, $found) === 1) {
                    $matches->orWhere('tasks.number', (int) $found[1]);
                }
            });
        }

        if ($filters->statusIds !== []) {
            $query->whereIn('tasks.status_id', $filters->statusIds);
        }

        if ($filters->priorities !== []) {
            $query->whereIn('tasks.priority', $filters->priorities);
        }

        if ($filters->unassignedOnly) {
            $query->whereNull('tasks.assignee_id');
        } elseif ($filters->assigneeIds !== []) {
            $query->whereIn('tasks.assignee_id', $filters->assigneeIds);
        }

        if ($filters->milestoneIds !== []) {
            $query->whereIn('tasks.milestone_id', $filters->milestoneIds);
        }

        // Every selected tag must be present, not any of them — the same reading the filter
        // bar gives it, so narrowing by two tags returns fewer rows rather than more.
        foreach ($filters->tagIds as $tagId) {
            $query->whereHas('tags', static fn (Builder $tag): Builder => $tag->whereKey($tagId));
        }

        if ($filters->overdueOnly) {
            $query->overdue();
        }

        $this->applyDueRange($query);

        if (! $filters->includeCompleted) {
            $query->whereNull('tasks.completed_at');
        }

        return $query;
    }

    /**
     * @param Builder<Task> $query
     */
    private function applyDueRange(Builder $query): void
    {
        // `Carbon::today()` rather than the workspace's today, because this has to agree
        // with the task list the filters were copied from, which reads the same clock.
        $today = Carbon::today();

        match ($this->filters->dueRange) {
            'overdue' => $query->overdue(),
            'today' => $query->dueBetween($today, $today),
            'week' => $query->dueBetween($today, $today->copy()->addDays(7)),
            'month' => $query->dueBetween($today, $today->copy()->addDays(30)),
            'none' => $query->whereNull('tasks.due_date'),
            default => null,
        };
    }

    /**
     * @return list<int>
     */
    private function visibleProjectIds(): array
    {
        if ($this->projectIds !== null) {
            return $this->projectIds;
        }

        $query = Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->reader);

        if (! $this->filters->includeArchived) {
            $query->active();
        }

        if ($this->filters->projectIds !== []) {
            $query->whereIn('projects.id', $this->filters->projectIds);
        }

        /** @var list<int> $ids */
        $ids = $query->orderBy('projects.name')->pluck('projects.id')->map(intval(...))->all();

        return $this->projectIds = $ids;
    }
}
