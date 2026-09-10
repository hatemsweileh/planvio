<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * The project register: one row per project the reader may open.
 *
 * Money is the one column that is not simply "what is stored". `budget.view` is a
 * per-project right (`+` in the matrix — a workspace manager only holds it inside projects
 * they manage), so the budget and currency cells are filled per row and left empty where
 * the reader may not see them. The header stays the same either way: a file whose columns
 * depend on who downloaded it cannot be compared with the one a colleague downloaded, and a
 * blank cell is honest where a zero would not be.
 */
final class ProjectExport implements CsvExport
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $reader,
        private readonly ExportFilters $filters,
    ) {}

    public function subject(): string
    {
        return 'projects';
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            __('Name'),
            __('Key'),
            __('Type'),
            __('Status'),
            __('Health'),
            __('Priority'),
            __('Owner'),
            __('Manager'),
            __('Client'),
            __('Department'),
            __('Start date'),
            __('Target date'),
            __('Completed at'),
            __('Progress %'),
            __('Tasks'),
            __('Tasks completed'),
            __('Open tasks'),
            __('Budget'),
            __('Currency'),
            __('Archived'),
            __('Created at'),
        ];
    }

    public function total(): int
    {
        return $this->query()->count();
    }

    /**
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(): Generator
    {
        $query = $this->query()
            ->with(['owner:id,name', 'manager:id,name', 'status:id,name'])
            ->withCount([
                'tasks',
                'tasks as tasks_completed_count' => static fn (Builder $q): Builder => $q->whereNotNull('tasks.completed_at'),
            ])
            ->orderBy('projects.name');

        // A workspace holds projects in the hundreds, not the millions, so chunked paging
        // is cheap and the alphabetical order the screen shows is worth keeping.
        foreach ($query->lazy(200) as $project) {
            yield $this->row($project);
        }
    }

    /**
     * @return list<scalar|null>
     */
    private function row(Project $project): array
    {
        $total = (int) ($project->tasks_count ?? 0);
        $completed = (int) ($project->tasks_completed_count ?? 0);
        $maySeeBudget = Gate::forUser($this->reader)->allows(Permission::BudgetView->value, $project);

        return [
            $project->name,
            $project->key,
            $project->type?->label(),
            $project->status?->name,
            $project->health?->label(),
            $project->priority?->label(),
            $project->owner?->name,
            $project->manager?->name,
            $project->client_name,
            $project->department,
            $project->start_date?->format('Y-m-d'),
            $project->target_date?->format('Y-m-d'),
            $project->completed_at?->format('Y-m-d H:i'),
            $project->progress,
            $total,
            $completed,
            max(0, $total - $completed),
            $maySeeBudget ? $project->budget : null,
            $maySeeBudget ? ($project->currency ?? $this->workspace->currency) : null,
            $project->is_archived ? __('Yes') : __('No'),
            $project->created_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return Builder<Project>
     */
    private function query(): Builder
    {
        $query = Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->reader);

        if (! $this->filters->includeArchived) {
            $query->active();
        }

        if ($this->filters->projectIds !== []) {
            $query->whereIn('projects.id', $this->filters->projectIds);
        }

        $term = trim($this->filters->search);

        if ($term !== '') {
            $query->where(function (Builder $matches) use ($term): void {
                $matches->where('projects.name', 'like', '%'.$term.'%')
                    ->orWhere('projects.key', 'like', '%'.$term.'%')
                    ->orWhere('projects.client_name', 'like', '%'.$term.'%');
            });
        }

        return $query;
    }
}
