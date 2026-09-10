<?php

declare(strict_types=1);

namespace App\Livewire\App\Tasks;

use App\Actions\Tags\AttachTag;
use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\BulkUpdateTasks;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Priority;
use App\Enums\ViewType;
use App\Livewire\App\Concerns\FiltersTasks;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The task list: every task in a project, as a table you can rearrange.
 *
 * A list view earns its keep by answering questions the board cannot — "what is due this
 * week, sorted by who owns it" — so three controls do most of the work:
 *
 *   - **Sort** rewrites the ORDER BY, including for the columns that live in another table.
 *     Status, assignee and milestone order by a correlated subquery rather than a join, so
 *     the filters compose unchanged and no row is duplicated by a many-to-many.
 *   - **Group** splits the page that came back, not the query. Grouping inside SQL would
 *     make pagination meaningless — page two of "grouped by assignee" is not a group.
 *   - **Select** turns the list into a bulk editor. The selection is bounded by the page,
 *     every selected task is authorised individually, and the edit itself goes through
 *     {@see BulkUpdateTasks} so the activity feed still gets a row per task.
 *
 * Filters, saved views and their URL representation come from {@see FiltersTasks}, which
 * the board shares, so the two surfaces cannot disagree about what "overdue, unassigned"
 * means.
 */
#[Layout('layouts.app')]
final class TaskList extends Component
{
    use FiltersTasks;
    use WithPagination;

    /**
     * Every column the table can show, in display order. `key` and `title` are not
     * hideable: a row with neither is not a row.
     */
    public const COLUMNS = [
        'key', 'title', 'status', 'priority', 'assignee',
        'start_date', 'due_date', 'milestone', 'progress', 'updated_at',
    ];

    private const FIXED_COLUMNS = ['key', 'title'];

    /**
     * Every grouping the list understands, in menu order. The URL is user input, so this
     * list is what both {@see groupBy()} and mount() measure a supplied value against —
     * an unknown key would otherwise reach the view as an array subscript.
     *
     * @var list<string>
     */
    public const GROUPS = ['none', 'status', 'assignee', 'priority', 'milestone'];

    public Workspace $workspace;

    public Project $project;

    #[Url(as: 'sort', except: 'position', history: true)]
    public string $sort = 'position';

    #[Url(as: 'dir', except: 'asc', history: true)]
    public string $direction = 'asc';

    #[Url(as: 'group', except: 'none', history: true)]
    public string $group = 'none';

    /**
     * Column visibility is a workstation preference, not a property of the data, so it
     * follows the person's session rather than the shareable URL.
     *
     * @var list<string>
     */
    #[Session(key: 'planvio.task-list.hidden-columns')]
    public array $hiddenColumns = [];

    /** @var list<int> */
    public array $selected = [];

    public bool $confirmingBulkDelete = false;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', [Task::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;

        // `sort`, `dir` and `group` arrive from the query string, which anyone can type.
        // The query builders already fall through to a default on an unknown value, but the
        // view reads `$group` as an array key, so an unrecognised one has to be corrected
        // here rather than survive as far as a subscript.
        if (! in_array($this->group, self::GROUPS, true)) {
            $this->group = 'none';
        }

        if (! in_array($this->sort, self::COLUMNS, true)) {
            $this->sort = 'position';
        }

        if (! in_array($this->direction, ['asc', 'desc'], true)) {
            $this->direction = 'asc';
        }
    }

    public function render(): View
    {
        return view('livewire.app.tasks.task-list')
            ->title($this->project->name.' · '.__('Tasks'));
    }

    /* ------------------------------------------------------------------ *
     * Rows
     * ------------------------------------------------------------------ */

    /**
     * @return LengthAwarePaginator<int, Task>
     */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        $query = $this->filteredTasks()
            ->with(['status', 'assignee', 'milestone', 'tags'])
            ->withCount([
                'subtasks',
                'comments',
                'attachments',
                'checklistItems',
                'checklistItems as checklist_items_done_count' => static fn (Builder $q) => $q->where('is_done', true),
            ]);

        $this->applySort($query);

        $paginator = $query->paginate((int) config('planvio.pagination.list', 50));

        // Every row prints its key, and the key needs the project. One instance, shared.
        foreach ($paginator->items() as $task) {
            $task->setRelation('project', $this->project);
        }

        return $paginator;
    }

    /**
     * The current page, split into the groups the header rows describe.
     *
     * @return list<array{key: string, label: string, color: string|null, rows: Collection<int, Task>}>
     */
    #[Computed]
    public function groups(): array
    {
        $rows = new Collection($this->tasks->items());

        if ($this->group === 'none' || $rows->isEmpty()) {
            return [[
                'key' => 'all',
                'label' => '',
                'color' => null,
                'rows' => $rows,
            ]];
        }

        $groups = [];

        foreach ($rows as $task) {
            [$key, $label, $color, $order] = $this->groupOf($task);

            $groups[$key] ??= ['key' => $key, 'label' => $label, 'color' => $color, 'order' => $order, 'rows' => new Collection];
            $groups[$key]['rows']->push($task);
        }

        uasort($groups, static fn (array $a, array $b): int => $a['order'] <=> $b['order'] ?: strcmp($a['label'], $b['label']));

        return array_values(array_map(
            static fn (array $group): array => [
                'key' => $group['key'],
                'label' => $group['label'],
                'color' => $group['color'],
                'rows' => $group['rows'],
            ],
            $groups,
        ));
    }

    /**
     * @return array{0: string, 1: string, 2: string|null, 3: int}
     */
    private function groupOf(Task $task): array
    {
        return match ($this->group) {
            'status' => (function () use ($task): array {
                $status = $task->getRelation('status');

                return $status instanceof TaskStatus
                    ? ['status-'.$status->getKey(), $status->name, $status->color, (int) $status->position]
                    : ['status-none', __('No status'), null, PHP_INT_MAX];
            })(),
            'assignee' => (function () use ($task): array {
                $assignee = $task->getRelation('assignee');

                return $assignee instanceof User
                    ? ['user-'.$assignee->getKey(), $assignee->name, null, 0]
                    : ['user-none', __('Unassigned'), null, PHP_INT_MAX];
            })(),
            'priority' => [
                'priority-'.$task->priority->value,
                $task->priority->label(),
                $task->priority->color(),
                4 - $task->priority->weight(),
            ],
            'milestone' => (function () use ($task): array {
                $milestone = $task->getRelation('milestone');

                return $milestone instanceof Milestone
                    ? ['milestone-'.$milestone->getKey(), $milestone->name, null, 0]
                    : ['milestone-none', __('No milestone'), null, PHP_INT_MAX];
            })(),
            default => ['all', '', null, 0],
        };
    }

    /* ------------------------------------------------------------------ *
     * Sorting, grouping and columns
     * ------------------------------------------------------------------ */

    /**
     * @param Builder<Task> $query
     */
    private function applySort(Builder $query): void
    {
        $direction = $this->direction === 'desc' ? 'desc' : 'asc';

        match ($this->sort) {
            'key' => $query->orderBy('tasks.number', $direction),
            'title' => $query->orderBy('tasks.title', $direction),
            'status' => $query->orderBy(
                TaskStatus::query()->select('position')->whereColumn('task_statuses.id', 'tasks.status_id'),
                $direction,
            ),
            'priority' => $query->orderByRaw($this->priorityOrdering().' '.$direction),
            'assignee' => $query->orderBy(
                User::query()->select('name')->whereColumn('users.id', 'tasks.assignee_id'),
                $direction,
            ),
            'start_date' => $query->orderBy('tasks.start_date', $direction),
            'due_date' => $query->orderBy('tasks.due_date', $direction),
            'milestone' => $query->orderBy(
                Milestone::query()->select('name')->whereColumn('milestones.id', 'tasks.milestone_id'),
                $direction,
            ),
            'progress' => $query->orderBy('tasks.progress', $direction),
            'updated_at' => $query->orderBy('tasks.updated_at', $direction),
            default => $query
                ->orderBy(
                    TaskStatus::query()->select('position')->whereColumn('task_statuses.id', 'tasks.status_id'),
                    $direction,
                )
                ->orderBy('tasks.position', $direction),
        };

        // A stable tiebreak: without it two tasks with the same due date can swap places
        // between page one and page two and a row is seen twice or not at all.
        $query->orderBy('tasks.id', $direction);
    }

    /**
     * `priority` is stored as a word, so alphabetical order would put "urgent" after
     * "none". The weights come from the enum, never from user input.
     */
    private function priorityOrdering(): string
    {
        $cases = '';

        foreach (Priority::cases() as $priority) {
            $cases .= sprintf(" when '%s' then %d", $priority->value, $priority->weight());
        }

        return 'case tasks.priority'.$cases.' else 0 end';
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, self::COLUMNS, true)) {
            return;
        }

        if ($this->sort === $column) {
            $this->direction = $this->direction === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $column;
            // Dates and recency read newest-first; names and keys read A to Z.
            $this->direction = in_array($column, ['due_date', 'start_date', 'updated_at', 'progress'], true) ? 'desc' : 'asc';
        }

        $this->afterFilterChange();
    }

    public function groupBy(string $group): void
    {
        $this->group = in_array($group, self::GROUPS, true) ? $group : 'none';

        $this->afterFilterChange();
    }

    public function toggleColumn(string $column): void
    {
        if (! in_array($column, self::COLUMNS, true) || in_array($column, self::FIXED_COLUMNS, true)) {
            return;
        }

        $hidden = array_values(array_intersect(self::COLUMNS, $this->hiddenColumns));
        $index = array_search($column, $hidden, true);

        if ($index === false) {
            $hidden[] = $column;
        } else {
            unset($hidden[$index]);
        }

        $this->hiddenColumns = array_values($hidden);
    }

    public function showsColumn(string $column): bool
    {
        return in_array($column, self::FIXED_COLUMNS, true) || ! in_array($column, $this->hiddenColumns, true);
    }

    /**
     * @return list<array{key: string, label: string, fixed: bool, sortable: bool}>
     */
    public function columnOptions(): array
    {
        $labels = [
            'key' => __('Key'),
            'title' => __('Title'),
            'status' => __('Status'),
            'priority' => __('Priority'),
            'assignee' => __('Assignee'),
            'start_date' => __('Start'),
            'due_date' => __('Due'),
            'milestone' => __('Milestone'),
            'progress' => __('Progress'),
            'updated_at' => __('Updated'),
        ];

        $options = [];

        foreach (self::COLUMNS as $column) {
            $options[] = [
                'key' => $column,
                'label' => $labels[$column],
                'fixed' => in_array($column, self::FIXED_COLUMNS, true),
                'sortable' => true,
            ];
        }

        return $options;
    }

    /* ------------------------------------------------------------------ *
     * Selection
     * ------------------------------------------------------------------ */

    public function toggleSelectPage(): void
    {
        $ids = array_map(static fn (Task $task): int => (int) $task->getKey(), $this->tasks->items());

        $this->selected = array_diff($ids, $this->selected) === []
            ? []
            : array_values(array_unique([...$this->selected, ...$ids]));
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->confirmingBulkDelete = false;
    }

    /**
     * The selected tasks, each one checked against the Gate.
     *
     * Selection is a client-side list of ids: it is a request, not a permission. Anything
     * this person may not edit is dropped here rather than handed to an Action.
     *
     * @return EloquentCollection<int, Task>
     */
    private function selectedTasks(string $ability): EloquentCollection
    {
        $ids = array_values(array_filter(array_map(intval(...), $this->selected)));

        if ($ids === []) {
            return new EloquentCollection;
        }

        $tasks = Task::query()
            ->forProject($this->project)
            ->whereKey($ids)
            ->limit((int) config('planvio.pagination.list', 50))
            ->get();

        foreach ($tasks as $task) {
            $task->setRelation('project', $this->project);
            $task->setRelation('workspace', $this->workspace);
        }

        return $tasks->filter(fn (Task $task): bool => $this->user()->can($ability, $task))->values();
    }

    /* ------------------------------------------------------------------ *
     * Bulk edits
     * ------------------------------------------------------------------ */

    public function bulkStatus(int $statusId): void
    {
        $status = TaskStatus::query()->forProject($this->project)->whereKey($statusId)->first();

        if (! $status instanceof TaskStatus) {
            return;
        }

        $this->applyBulk('changeStatus', TaskChanges::make()->status($status));
    }

    public function bulkPriority(string $priority): void
    {
        $case = Priority::tryFrom($priority);

        if (! $case instanceof Priority) {
            return;
        }

        $this->applyBulk('update', TaskChanges::make()->priority($case));
    }

    public function bulkAssign(?int $userId): void
    {
        $assignee = $userId === null ? null : $this->memberOptions->firstWhere('id', $userId);

        if ($userId !== null && ! $assignee instanceof User) {
            return;
        }

        $this->applyBulk('assign', TaskChanges::make()->assignee($assignee));
    }

    public function bulkMilestone(?int $milestoneId): void
    {
        $milestone = $milestoneId === null
            ? null
            : Milestone::query()->forProject($this->project)->whereKey($milestoneId)->first();

        if ($milestoneId !== null && ! $milestone instanceof Milestone) {
            return;
        }

        $this->applyBulk('update', TaskChanges::make()->milestone($milestone));
    }

    private function applyBulk(string $ability, TaskChanges $changes): void
    {
        $tasks = $this->selectedTasks($ability);

        if ($tasks->isEmpty()) {
            $this->dispatch('planvio-notify', type: 'error', message: __('You cannot edit the selected tasks.'));

            return;
        }

        $changed = app(BulkUpdateTasks::class)($tasks->modelKeys(), $changes, $this->user());

        $this->afterBulk($changed);
    }

    public function bulkTag(int $tagId): void
    {
        $tag = Tag::query()->forWorkspace($this->workspace)->whereKey($tagId)->first();

        if (! $tag instanceof Tag) {
            return;
        }

        $tasks = $this->selectedTasks('update');

        if ($tasks->isEmpty()) {
            $this->dispatch('planvio-notify', type: 'error', message: __('You cannot edit the selected tasks.'));

            return;
        }

        $attach = app(AttachTag::class);

        foreach ($tasks as $task) {
            $attach($task, $tag, $this->user());
        }

        $this->afterBulk($tasks->count());
    }

    public function bulkDelete(): void
    {
        $tasks = $this->selectedTasks('delete');

        if ($tasks->isEmpty()) {
            $this->confirmingBulkDelete = false;
            $this->dispatch('planvio-notify', type: 'error', message: __('You cannot delete the selected tasks.'));

            return;
        }

        $delete = app(DeleteTask::class);

        foreach ($tasks as $task) {
            $delete($task, $this->user());
        }

        $this->confirmingBulkDelete = false;

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: trans_choice('{1}:count task deleted.|[2,*]:count tasks deleted.', $tasks->count(), ['count' => $tasks->count()]),
        );

        $this->selected = [];
        $this->forgetTaskCaches();
    }

    private function afterBulk(int $changed): void
    {
        $this->selected = [];
        $this->forgetTaskCaches();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: $changed === 0
                ? __('Nothing to change — those tasks already matched.')
                : trans_choice('{1}:count task updated.|[2,*]:count tasks updated.', $changed, ['count' => $changed]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Inline edits
     * ------------------------------------------------------------------ */

    public function setStatus(int $taskId, int $statusId): void
    {
        $task = $this->authorizedRow($taskId, 'changeStatus');
        $status = TaskStatus::query()->forProject($this->project)->whereKey($statusId)->first();

        if (! $task instanceof Task || ! $status instanceof TaskStatus) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->status($status), $this->user());

        $this->forgetTaskCaches();
        $this->dispatch('task-updated', taskId: $taskId);
    }

    public function setPriority(int $taskId, string $priority): void
    {
        $task = $this->authorizedRow($taskId, 'update');
        $case = Priority::tryFrom($priority);

        if (! $task instanceof Task || ! $case instanceof Priority) {
            return;
        }

        app(UpdateTask::class)($task, TaskChanges::make()->priority($case), $this->user());

        $this->forgetTaskCaches();
        $this->dispatch('task-updated', taskId: $taskId);
    }

    public function setAssignee(int $taskId, ?int $userId): void
    {
        $task = $this->authorizedRow($taskId, 'assign');
        $assignee = $userId === null ? null : $this->memberOptions->firstWhere('id', $userId);

        if (! $task instanceof Task || ($userId !== null && ! $assignee instanceof User)) {
            return;
        }

        app(AssignTask::class)($task, $assignee, $this->user());

        $this->forgetTaskCaches();
        $this->dispatch('task-updated', taskId: $taskId);
    }

    private function authorizedRow(int $taskId, string $ability): ?Task
    {
        $task = Task::query()->forProject($this->project)->whereKey($taskId)->first();

        if (! $task instanceof Task) {
            return null;
        }

        $task->setRelation('project', $this->project);
        $task->setRelation('workspace', $this->workspace);

        $this->authorize($ability, $task);

        return $task;
    }

    /* ------------------------------------------------------------------ *
     * Cross-component refresh
     * ------------------------------------------------------------------ */

    #[On('task-updated')]
    #[On('task-created')]
    #[On('task-deleted')]
    public function refreshRows(?int $taskId = null): void
    {
        $this->forgetTaskCaches();
    }

    /* ------------------------------------------------------------------ *
     * FiltersTasks hooks
     * ------------------------------------------------------------------ */

    protected function viewType(): ViewType
    {
        return ViewType::List;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function savedSorts(): array
    {
        return ['column' => $this->sort, 'direction' => $this->direction];
    }

    /**
     * @return list<string>
     */
    protected function savedColumns(): array
    {
        return array_values(array_filter(self::COLUMNS, fn (string $column): bool => $this->showsColumn($column)));
    }

    protected function savedGroupBy(): ?string
    {
        return $this->group === 'none' ? null : $this->group;
    }

    protected function restoreSavedArrangement(SavedView $view): void
    {
        $sorts = $view->sorts ?? [];

        if (is_array($sorts) && in_array($sorts['column'] ?? null, self::COLUMNS, true)) {
            $this->sort = (string) $sorts['column'];
            $this->direction = ($sorts['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        $columns = $view->columns;

        if (is_array($columns) && $columns !== []) {
            $this->hiddenColumns = array_values(array_diff(self::COLUMNS, $columns, self::FIXED_COLUMNS));
        }

        $this->group = $view->group_by !== null && in_array($view->group_by, self::GROUPS, true)
            ? (string) $view->group_by
            : 'none';
    }

    protected function forgetTaskCaches(): void
    {
        unset($this->tasks, $this->groups);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
