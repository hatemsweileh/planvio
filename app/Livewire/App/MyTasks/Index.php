<?php

declare(strict_types=1);

namespace App\Livewire\App\MyTasks;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Priority;
use App\Livewire\App\Concerns\FormatsWorkDates;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Everything assigned to one person, across every project they can open.
 *
 * The list is grouped by project and paginated, in that order — which means the groups are
 * page-local by design. Ordering by project first keeps each group contiguous inside a page;
 * the alternative, fetching every task so the grouping could be global, is exactly the
 * unbounded query this product does not allow itself.
 *
 * Status and priority change in place. Both go through the domain actions
 * ({@see ChangeTaskStatus}, {@see UpdateTask}) rather than writing columns here, because both
 * carry consequences a component has no business reimplementing: completion stamps, watcher
 * notifications, activity rows. This component's job is the half the actions deliberately do
 * not do — it authorizes first, every time.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use FormatsWorkDates;
    use WithPagination;

    private const TABS = ['today', 'upcoming', 'overdue', 'completed', 'all'];

    /** Projects offered by quick-add. Beyond this the command palette is the better tool. */
    private const PROJECT_OPTIONS = 100;

    public Workspace $workspace;

    #[Url(except: 'today')]
    public string $tab = 'today';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /* Quick add ------------------------------------------------------- */

    public string $newTitle = '';

    public ?int $newProjectId = null;

    public string $newDueDate = '';

    public string $newPriority = 'medium';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'today';
        }

        if ($this->canQuickAdd()) {
            $this->mountedDefaultProject();
        }
    }

    /**
     * `task.create` carries no project refinement in the capability matrix, so the
     * workspace-level answer is exact: a guest cannot add work anywhere, everybody else can
     * add it to any project they can open.
     */
    public function canQuickAdd(): bool
    {
        return Gate::allows('task.create', [Task::class, $this->workspace]);
    }

    /* ------------------------------------------------------------------ *
     * Filtering
     * ------------------------------------------------------------------ */

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'today';

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /**
     * The five tab counts, from one aggregate. Counting them separately would be five
     * round trips to answer one question.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $empty = array_fill_keys(self::TABS, 0);

        $user = $this->user();

        if (! $user instanceof User) {
            return $empty;
        }

        $today = $this->today->toDateString();
        $tomorrow = $this->today->addDay()->toDateString();

        $row = $this->assigned($user)
            ->selectRaw(
                'count(case when tasks.completed_at is null and tasks.due_date >= ?'
                .' and tasks.due_date < ? then 1 end) as today_count',
                [$today, $tomorrow],
            )
            ->selectRaw(
                'count(case when tasks.completed_at is null and tasks.due_date >= ? then 1 end) as upcoming_count',
                [$tomorrow],
            )
            ->selectRaw(
                'count(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date < ? then 1 end) as overdue_count',
                [$today],
            )
            ->selectRaw('count(case when tasks.completed_at is not null then 1 end) as completed_count')
            ->selectRaw('count(*) as all_count')
            ->toBase()
            ->first();

        if ($row === null) {
            return $empty;
        }

        return [
            'today' => (int) $row->today_count,
            'upcoming' => (int) $row->upcoming_count,
            'overdue' => (int) $row->overdue_count,
            'completed' => (int) $row->completed_count,
            'all' => (int) $row->all_count,
        ];
    }

    /**
     * @return list<array{key: string, label: string, count: int, icon: string}>
     */
    public function tabs(): array
    {
        $counts = $this->counts;

        return [
            ['key' => 'today', 'label' => __('Today'), 'icon' => 'icon.check-circle', 'count' => $counts['today']],
            ['key' => 'upcoming', 'label' => __('Upcoming'), 'icon' => 'icon.calendar', 'count' => $counts['upcoming']],
            ['key' => 'overdue', 'label' => __('Overdue'), 'icon' => 'icon.warning', 'count' => $counts['overdue']],
            ['key' => 'completed', 'label' => __('Completed'), 'icon' => 'icon.archive', 'count' => $counts['completed']],
            ['key' => 'all', 'label' => __('All assigned'), 'icon' => 'icon.list', 'count' => $counts['all']],
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Task>
     */
    #[Computed]
    public function tasks(): LengthAwarePaginator
    {
        $user = $this->user();

        if (! $user instanceof User) {
            /** @var LengthAwarePaginator<int, Task> $empty */
            $empty = Task::query()->whereRaw('1 = 0')->paginate(1);

            return $empty;
        }

        $today = $this->today->toDateString();
        $tomorrow = $this->today->addDay()->toDateString();

        $query = $this->assigned($user);

        match ($this->tab) {
            'today' => $query->open()
                ->where('tasks.due_date', '>=', $today)
                ->where('tasks.due_date', '<', $tomorrow),
            'upcoming' => $query->open()->where('tasks.due_date', '>=', $tomorrow),
            'overdue' => $query->open()
                ->whereNotNull('tasks.due_date')
                ->where('tasks.due_date', '<', $today),
            'completed' => $query->completed(),
            default => $query,
        };

        // Project first so each group is contiguous within the page, then the natural order
        // for the tab. The project name is a correlated sub-select rather than a join: a join
        // would drag `projects` through the soft-delete and tenant scopes for the sake of a
        // sort key.
        $query->orderBy(
            DB::table('projects')->select('projects.name')->whereColumn('projects.id', 'tasks.project_id'),
        );

        if ($this->tab === 'completed') {
            $query->orderByDesc('tasks.completed_at');
        } else {
            $query->orderByRaw('case when tasks.due_date is null then 1 else 0 end')
                ->orderBy('tasks.due_date');
        }

        return $query
            ->orderBy('tasks.id')
            ->with([
                'project:id,name,slug,key,color',
                // The columns a project's board offers, loaded once for the whole page rather
                // than once per row: this is what makes the inline status menu free.
                'project.taskStatuses' => static fn ($query) => $query
                    ->select(['id', 'project_id', 'name', 'color', 'category', 'position', 'is_completed', 'is_default'])
                    ->orderBy('position')
                    ->orderBy('id'),
                'status:id,project_id,name,color,category,is_completed',
            ])
            ->paginate((int) config('planvio.pagination.list', 50));
    }

    /**
     * The current page, folded into project groups in the order the query returned them.
     *
     * @return list<array{project: ?Project, tasks: list<Task>}>
     */
    #[Computed]
    public function groups(): array
    {
        $groups = [];

        foreach ($this->tasks as $task) {
            $key = (string) $task->project_id;

            $groups[$key] ??= ['project' => $task->project, 'tasks' => []];
            $groups[$key]['tasks'][] = $task;
        }

        return array_values($groups);
    }

    /* ------------------------------------------------------------------ *
     * Inline edits
     * ------------------------------------------------------------------ */

    public function setStatus(int $taskId, int $statusId): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $task = $this->findTask($taskId);

        if ($task === null) {
            return;
        }

        $this->authorize('changeStatus', $task);

        $status = TaskStatus::query()
            ->forWorkspace($this->workspace)
            ->where('project_id', $task->project_id)
            ->whereKey($statusId)
            ->first();

        if ($status === null) {
            return;
        }

        try {
            app(ChangeTaskStatus::class)($task, $status, $user);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->refresh();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __('Moved to :status.', ['status' => $status->name]),
        );
    }

    public function setPriority(int $taskId, string $priority): void
    {
        $user = $this->user();
        $case = Priority::tryFrom($priority);

        if (! $user instanceof User || $case === null) {
            return;
        }

        $task = $this->findTask($taskId);

        if ($task === null) {
            return;
        }

        $this->authorize('update', $task);

        try {
            app(UpdateTask::class)($task, TaskChanges::make()->priority($case), $user);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->refresh();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __('Priority set to :priority.', ['priority' => $case->label()]),
        );
    }

    /**
     * The checkbox. Closing moves the task into the project's completed column; reopening
     * puts it back in the default one, so the round trip is symmetrical and nothing has to
     * remember where it came from.
     */
    public function toggleComplete(int $taskId): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $task = $this->findTask($taskId);

        if ($task === null) {
            return;
        }

        $this->authorize('changeStatus', $task);

        $statuses = TaskStatus::query()
            ->forWorkspace($this->workspace)
            ->where('project_id', $task->project_id)
            ->ordered()
            ->get();

        $target = $task->completed_at === null
            ? $statuses->first(static fn (TaskStatus $status): bool => $status->is_completed)
            : ($statuses->first(static fn (TaskStatus $status): bool => $status->is_default && ! $status->is_completed)
                ?? $statuses->first(static fn (TaskStatus $status): bool => ! $status->is_completed));

        if (! $target instanceof TaskStatus) {
            $this->dispatch(
                'planvio-notify',
                type: 'error',
                message: __('This project has no column to move the task into.'),
            );

            return;
        }

        try {
            app(ChangeTaskStatus::class)($task, $target, $user);
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->refresh();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: $target->is_completed
                ? __('Done — nice.')
                : __('Reopened in :status.', ['status' => $target->name]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Quick add
     * ------------------------------------------------------------------ */

    /**
     * Active projects the viewer can open. `task.create` carries no project refinement in
     * the capability matrix, so a workspace-level check answers it exactly and the list can
     * be built without asking the gate once per row.
     *
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function assignableProjects(): EloquentCollection
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return new EloquentCollection;
        }

        return Project::query()
            ->forWorkspace($this->workspace)
            ->active()
            ->visibleTo($user)
            ->orderBy('name')
            ->limit(self::PROJECT_OPTIONS)
            ->get(['id', 'name', 'key', 'color']);
    }

    /**
     * The composer defaults to the project the reader is most likely to mean — the first one
     * alphabetically is a poor guess, but a pre-filled select beats an empty one, and the
     * choice is one click away.
     */
    public function mountedDefaultProject(): void
    {
        if ($this->newProjectId === null) {
            $this->newProjectId = $this->assignableProjects->first()?->getKey();
        }
    }

    public function quickAdd(): void
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return;
        }

        $rules = [
            'newTitle' => ['required', 'string', 'min:2', 'max:255'],
            'newProjectId' => ['required', 'integer'],
            'newPriority' => ['required', Rule::in(array_column(Priority::cases(), 'value'))],
        ];

        // An untouched date field arrives as an empty string, which `nullable` does not
        // treat as absent. The rule is added only when there is something to check.
        $this->newDueDate = trim($this->newDueDate);

        if ($this->newDueDate !== '') {
            $rules['newDueDate'] = ['date'];
        }

        $validated = $this->validate($rules, attributes: [
            'newTitle' => __('title'),
            'newProjectId' => __('project'),
            'newDueDate' => __('due date'),
            'newPriority' => __('priority'),
        ]);

        // Resolved through the visibility scope, so an id from another project — or another
        // tenant — is a validation failure rather than a cross-workspace write.
        $project = Project::query()
            ->forWorkspace($this->workspace)
            ->active()
            ->visibleTo($user)
            ->whereKey($validated['newProjectId'])
            ->first();

        if ($project === null) {
            $this->addError('newProjectId', __('Pick a project you can add work to.'));

            return;
        }

        $this->authorize('create', [Task::class, $project]);

        $due = $this->newDueDate === ''
            ? null
            : CarbonImmutable::parse($this->newDueDate, $this->timezone)->startOfDay();

        try {
            $task = app(CreateTask::class)(new CreateTaskData(
                project: $project,
                actor: $user,
                title: $validated['newTitle'],
                priority: Priority::from($validated['newPriority']),
                assignee: $user,
                dueDate: $due,
            ));
        } catch (Throwable $exception) {
            $this->fail($exception);

            return;
        }

        $this->reset(['newTitle', 'newDueDate']);
        $this->refresh();

        $task->setRelation('project', $project);

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __(':key added to :project.', ['key' => $task->key, 'project' => $project->name]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Copy
     * ------------------------------------------------------------------ */

    /**
     * The empty state differs per tab, because an empty "Today" is a different fact from an
     * empty "All assigned" and saying the same thing for both is how a product starts
     * feeling generic.
     *
     * @return array{title: string, description: string}
     */
    public function emptyCopy(): array
    {
        if ($this->search !== '') {
            return [
                'title' => __('Nothing matches “:term”', ['term' => $this->search]),
                'description' => __('Try a shorter phrase, or clear the search to see everything in this tab.'),
            ];
        }

        return match ($this->tab) {
            'today' => [
                'title' => __('Nothing is due today'),
                'description' => __('A clear day. Pull something forward from Upcoming, or add a task below.'),
            ],
            'upcoming' => [
                'title' => __('Nothing scheduled ahead'),
                'description' => __('Work with a due date in the future lands here, so you can see the week before it arrives.'),
            ],
            'overdue' => [
                'title' => __('Nothing is late'),
                'description' => __('Every dated task assigned to you is still within its deadline.'),
            ],
            'completed' => [
                'title' => __('Nothing finished yet'),
                'description' => __('Tasks you close appear here, newest first, so a week of work is easy to look back on.'),
            ],
            default => [
                'title' => __('Nothing is assigned to you'),
                'description' => __('Work assigned to you in any project you can open shows up here. Add the first one below.'),
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    public function priorityOptions(): array
    {
        return Priority::options();
    }

    public function render(): View
    {
        return view('livewire.app.my-tasks.index')->title(__('My Tasks'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function refresh(): void
    {
        unset($this->tasks, $this->groups, $this->counts);
    }

    /**
     * Tasks assigned to this person, in a project they can open.
     *
     * The project check is not redundant with the workspace scope. A guest belongs to the
     * workspace but only to the projects they were added to, and a task assigned to them and
     * then moved elsewhere must not stay visible here.
     *
     * @return Builder<Task>
     */
    private function assigned(User $user): Builder
    {
        $term = trim($this->search);

        return Task::query()
            ->forWorkspace($this->workspace)
            ->assignedTo($user)
            ->whereHas('project', static fn (Builder $query): Builder => $query->visibleTo($user))
            ->when($term !== '', function (Builder $query) use ($term): void {
                // The wildcards belong to the query, not to the person typing: a title
                // containing % or _ has to search for itself, not for everything.
                $escaped = addcslashes($term, '%_\\');

                $query->where(function (Builder $inner) use ($escaped, $term): void {
                    $inner->where('tasks.title', 'like', '%'.$escaped.'%');

                    if (ctype_digit($term)) {
                        $inner->orWhere('tasks.number', (int) $term);
                    }
                });
            });
    }

    /**
     * One task, resolved inside the tenant and hydrated with everything the actions behind
     * it will reach for. The workspace is handed over rather than looked up: the action's
     * notification step reads it, and an unloaded relation there would trip the
     * no-lazy-loading guard.
     */
    private function findTask(int $taskId): ?Task
    {
        $task = Task::query()
            ->forWorkspace($this->workspace)
            ->with(['project:id,name,slug,key,color', 'status'])
            ->whereKey($taskId)
            ->first();

        if ($task === null) {
            return null;
        }

        $task->setRelation('workspace', $this->workspace);

        return $task;
    }

    private function fail(Throwable $exception): void
    {
        report($exception);

        $this->dispatch(
            'planvio-notify',
            type: 'error',
            message: __('That change could not be saved. Nothing was altered.'),
        );
    }
}
