<?php

declare(strict_types=1);

namespace App\Livewire\App\Concerns;

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\PinSavedView;
use App\Enums\Priority;
use App\Enums\ViewType;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

/**
 * The task filter bar, shared by the list and the board.
 *
 * The two surfaces are the same question asked twice — "which of this project's tasks am I
 * looking at?" — and the fastest way to make them disagree is to write the predicate twice.
 * Everything that narrows a task query lives here: the state, its URL representation, the
 * option lists behind each control, and the saved views that store a whole filter set.
 *
 * Two properties are load-bearing:
 *
 *   - **The state is the URL.** Every filter is a `#[Url]` property, so a filtered board is
 *     a link somebody can paste into a comment and a colleague opens on the same view. That
 *     also makes back/forward work, and makes a saved view nothing more cryptic than a
 *     stored copy of these values.
 *   - **The predicate is one method.** {@see applyTaskFilters()} is the only place a filter
 *     turns into SQL. The list paginates it, the board runs it once per column, and neither
 *     can drift from the other because neither writes its own `where`.
 *
 * Host components supply `$workspace` and `$project` and declare which {@see ViewType} they
 * save views as.
 *
 * @property-read Workspace $workspace
 * @property-read Project $project
 */
trait FiltersTasks
{
    /**
     * The due windows the filter bar offers, beside the empty "any time". This is the list
     * every entry point measures a supplied value against — {@see setDueRange()}, a restored
     * saved view, and the query string, which anyone can type by hand.
     *
     * @var list<string>
     */
    public const DUE_RANGES = ['overdue', 'today', 'week', 'month', 'none'];

    /**
     * Free text, matched against the title and against the task key ("WEB-42" or "42").
     */
    #[Url(as: 'q', except: '', history: true)]
    public string $search = '';

    /** @var list<int> */
    #[Url(as: 'status', except: [], history: true)]
    public array $statusIds = [];

    /** @var list<int> */
    #[Url(as: 'assignee', except: [], history: true)]
    public array $assigneeIds = [];

    /** @var list<string> */
    #[Url(as: 'priority', except: [], history: true)]
    public array $priorities = [];

    /** @var list<int> */
    #[Url(as: 'tag', except: [], history: true)]
    public array $tagIds = [];

    /** @var list<int> */
    #[Url(as: 'milestone', except: [], history: true)]
    public array $milestoneIds = [];

    /**
     * One of: '', 'overdue', 'today', 'week', 'month', 'none'.
     */
    #[Url(as: 'due', except: '', history: true)]
    public string $dueRange = '';

    #[Url(as: 'overdue', except: false, history: true)]
    public bool $overdueOnly = false;

    #[Url(as: 'unassigned', except: false, history: true)]
    public bool $unassignedOnly = false;

    /**
     * Closed work is hidden by default: a list that opens on two years of finished tasks
     * answers a question nobody asked.
     */
    #[Url(as: 'done', except: false, history: true)]
    public bool $includeCompleted = false;

    /**
     * The saved view currently applied, so the chip stays lit after a reload.
     */
    #[Url(as: 'view', except: null, history: true)]
    public ?int $appliedViewId = null;

    public string $newViewName = '';

    public bool $newViewShared = false;

    /**
     * Livewire runs this for every component using the trait, right after the query string
     * has been read and before the component's own mount(). The due window is the one filter
     * the bar prints by looking itself up in a label map, so a value the map has never heard
     * of has to be discarded here — the predicate would merely ignore it, but the view would
     * fail on the subscript.
     */
    public function mountFiltersTasks(): void
    {
        if ($this->dueRange !== '' && ! in_array($this->dueRange, self::DUE_RANGES, true)) {
            $this->dueRange = '';
        }
    }

    /* ------------------------------------------------------------------ *
     * The predicate
     * ------------------------------------------------------------------ */

    /**
     * Every task in this project that survives the current filter bar.
     *
     * @return Builder<Task>
     */
    public function filteredTasks(): Builder
    {
        return $this->applyTaskFilters(
            Task::query()->forProject($this->project)->whereNull('tasks.parent_id'),
        );
    }

    /**
     * @param Builder<Task> $query
     * @return Builder<Task>
     */
    public function applyTaskFilters(Builder $query): Builder
    {
        $term = trim($this->search);

        if ($term !== '') {
            $query->where(function (Builder $matches) use ($term): void {
                $matches->where('tasks.title', 'like', '%'.$term.'%');

                $number = $this->numberIn($term);

                if ($number !== null) {
                    $matches->orWhere('tasks.number', $number);
                }
            });
        }

        if ($this->statusIds !== []) {
            $query->whereIn('tasks.status_id', $this->intList($this->statusIds));
        }

        if ($this->priorities !== []) {
            $query->whereIn('tasks.priority', $this->priorityValues());
        }

        if ($this->unassignedOnly) {
            $query->whereNull('tasks.assignee_id');
        } elseif ($this->assigneeIds !== []) {
            $query->whereIn('tasks.assignee_id', $this->intList($this->assigneeIds));
        }

        if ($this->milestoneIds !== []) {
            $query->whereIn('tasks.milestone_id', $this->intList($this->milestoneIds));
        }

        if ($this->tagIds !== []) {
            $tagIds = $this->intList($this->tagIds);

            // Every selected tag must be present, not any of them: narrowing by two tags
            // should give fewer results, never more.
            foreach ($tagIds as $tagId) {
                $query->whereHas('tags', static function (Builder $tag) use ($tagId): void {
                    $tag->whereKey($tagId);
                });
            }
        }

        if ($this->overdueOnly) {
            $query->overdue();
        }

        $this->applyDueRange($query);

        if (! $this->includeCompleted && ! $this->showsCompletedExplicitly()) {
            $query->whereNull('tasks.completed_at');
        }

        return $query;
    }

    /**
     * @param Builder<Task> $query
     */
    private function applyDueRange(Builder $query): void
    {
        $today = Carbon::today();

        match ($this->dueRange) {
            'overdue' => $query->overdue(),
            'today' => $query->dueBetween($today, $today),
            'week' => $query->dueBetween($today, $today->copy()->addDays(7)),
            'month' => $query->dueBetween($today, $today->copy()->addDays(30)),
            'none' => $query->whereNull('tasks.due_date'),
            default => null,
        };
    }

    /**
     * Picking a completed column by hand is an explicit request to see closed work, and
     * hiding it afterwards would leave an empty screen with a filter chip pointing at it.
     */
    private function showsCompletedExplicitly(): bool
    {
        if ($this->statusIds === []) {
            return false;
        }

        return $this->statusOptions
            ->whereIn('id', $this->intList($this->statusIds))
            ->contains(static fn (TaskStatus $status): bool => $status->is_completed || $status->category->isClosed());
    }

    /* ------------------------------------------------------------------ *
     * Filter state
     * ------------------------------------------------------------------ */

    public function hasFilters(): bool
    {
        return $this->activeFilterCount() > 0;
    }

    public function activeFilterCount(): int
    {
        return count(array_filter([
            trim($this->search) !== '',
            $this->statusIds !== [],
            $this->assigneeIds !== [],
            $this->priorities !== [],
            $this->tagIds !== [],
            $this->milestoneIds !== [],
            $this->dueRange !== '',
            $this->overdueOnly,
            $this->unassignedOnly,
            $this->includeCompleted,
        ]));
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusIds = [];
        $this->assigneeIds = [];
        $this->priorities = [];
        $this->tagIds = [];
        $this->milestoneIds = [];
        $this->dueRange = '';
        $this->overdueOnly = false;
        $this->unassignedOnly = false;
        $this->includeCompleted = false;
        $this->appliedViewId = null;

        $this->afterFilterChange();
    }

    /**
     * Add or remove one value from a multi-select filter.
     */
    public function toggleFilter(string $filter, int|string $value): void
    {
        if (! in_array($filter, ['statusIds', 'assigneeIds', 'priorities', 'tagIds', 'milestoneIds'], true)) {
            return;
        }

        $current = $this->{$filter};
        $value = $filter === 'priorities' ? (string) $value : (int) $value;
        $index = array_search($value, $current, true);

        if ($index === false) {
            $current[] = $value;
        } else {
            unset($current[$index]);
        }

        $this->{$filter} = array_values($current);
        $this->appliedViewId = null;

        $this->afterFilterChange();
    }

    public function setDueRange(string $range): void
    {
        $this->dueRange = in_array($range, self::DUE_RANGES, true) ? $range : '';
        $this->appliedViewId = null;

        $this->afterFilterChange();
    }

    /**
     * The filter bar as plain data — what a saved view stores and what it restores.
     *
     * @return array<string, mixed>
     */
    public function filterState(): array
    {
        return [
            'search' => trim($this->search),
            'statusIds' => $this->intList($this->statusIds),
            'assigneeIds' => $this->intList($this->assigneeIds),
            'priorities' => $this->priorityValues(),
            'tagIds' => $this->intList($this->tagIds),
            'milestoneIds' => $this->intList($this->milestoneIds),
            'dueRange' => $this->dueRange,
            'overdueOnly' => $this->overdueOnly,
            'unassignedOnly' => $this->unassignedOnly,
            'includeCompleted' => $this->includeCompleted,
        ];
    }

    /**
     * @param array<array-key, mixed> $filters
     */
    public function applyFilterState(array $filters): void
    {
        $this->search = is_string($filters['search'] ?? null) ? $filters['search'] : '';
        $this->statusIds = $this->intList($filters['statusIds'] ?? []);
        $this->assigneeIds = $this->intList($filters['assigneeIds'] ?? []);
        $this->tagIds = $this->intList($filters['tagIds'] ?? []);
        $this->milestoneIds = $this->intList($filters['milestoneIds'] ?? []);
        $this->overdueOnly = (bool) ($filters['overdueOnly'] ?? false);
        $this->unassignedOnly = (bool) ($filters['unassignedOnly'] ?? false);
        $this->includeCompleted = (bool) ($filters['includeCompleted'] ?? false);

        $range = $filters['dueRange'] ?? '';
        $this->dueRange = is_string($range) && in_array($range, self::DUE_RANGES, true)
            ? $range
            : '';

        $priorities = $filters['priorities'] ?? [];
        $this->priorities = is_array($priorities)
            ? array_values(array_filter(
                array_map(static fn (mixed $value): string => (string) $value, $priorities),
                static fn (string $value): bool => Priority::tryFrom($value) !== null,
            ))
            : [];
    }

    /* ------------------------------------------------------------------ *
     * Option lists
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function statusOptions(): EloquentCollection
    {
        return TaskStatus::query()->forProject($this->project)->ordered()->get();
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function memberOptions(): EloquentCollection
    {
        return $this->workspace->members()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_path']);
    }

    /**
     * @return EloquentCollection<int, Tag>
     */
    #[Computed]
    public function tagOptions(): EloquentCollection
    {
        return Tag::query()->forWorkspace($this->workspace)->ordered()->get(['id', 'name', 'color']);
    }

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function milestoneOptions(): EloquentCollection
    {
        return Milestone::query()->forProject($this->project)->ordered()->get(['id', 'name', 'status', 'due_date']);
    }

    /**
     * @return list<Priority>
     */
    public function priorityOptions(): array
    {
        return array_reverse(Priority::cases());
    }

    /* ------------------------------------------------------------------ *
     * Saved views
     * ------------------------------------------------------------------ */

    /**
     * The views this person may open here: their own, plus everything shared.
     *
     * @return EloquentCollection<int, SavedView>
     */
    #[Computed]
    public function savedViews(): EloquentCollection
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return new EloquentCollection;
        }

        return SavedView::query()
            ->forProject($this->project)
            ->ofType($this->viewType())
            ->visibleTo($user)
            ->ordered()
            ->get();
    }

    public function saveCurrentView(): void
    {
        $user = auth()->user();
        $name = trim($this->newViewName);

        if (! $user instanceof User || $name === '') {
            return;
        }

        $this->authorize('create', [SavedView::class, $this->project]);

        if ($this->newViewShared) {
            // Sharing a view puts it in front of the whole workspace, which the matrix
            // treats as a separate right from keeping one for yourself.
            $this->authorize('share', new SavedView([
                'workspace_id' => $this->workspace->getKey(),
                'project_id' => $this->project->getKey(),
                'user_id' => $user->getKey(),
            ]));
        }

        $view = app(CreateSavedView::class)(
            workspace: $this->workspace,
            owner: $user,
            name: $name,
            type: $this->viewType(),
            filters: $this->filterState(),
            project: $this->project,
            sorts: $this->savedSorts(),
            columns: $this->savedColumns(),
            groupBy: $this->savedGroupBy(),
            isShared: $this->newViewShared,
        );

        $this->newViewName = '';
        $this->newViewShared = false;
        $this->appliedViewId = (int) $view->getKey();

        unset($this->savedViews);

        $this->dispatch('planvio-notify', type: 'success', message: __('View saved.'));
    }

    public function applySavedView(int $viewId): void
    {
        $view = $this->resolveSavedView($viewId);

        if (! $view instanceof SavedView) {
            return;
        }

        $this->authorize('view', $view);

        $this->applyFilterState($view->filters ?? []);
        $this->restoreSavedArrangement($view);

        $this->appliedViewId = (int) $view->getKey();

        $this->afterFilterChange();
    }

    public function togglePinnedView(int $viewId): void
    {
        $view = $this->resolveSavedView($viewId);

        if (! $view instanceof SavedView) {
            return;
        }

        $this->authorize('pin', $view);

        app(PinSavedView::class)($view, auth()->user(), ! $view->is_pinned);

        unset($this->savedViews);
    }

    public function deleteSavedView(int $viewId): void
    {
        $view = $this->resolveSavedView($viewId);

        if (! $view instanceof SavedView) {
            return;
        }

        $this->authorize('delete', $view);

        app(DeleteSavedView::class)($view, auth()->user());

        if ($this->appliedViewId === $viewId) {
            $this->appliedViewId = null;
        }

        unset($this->savedViews);

        $this->dispatch('planvio-notify', type: 'success', message: __('View deleted.'));
    }

    private function resolveSavedView(int $viewId): ?SavedView
    {
        return SavedView::query()
            ->forProject($this->project)
            ->whereKey($viewId)
            ->first();
    }

    /* ------------------------------------------------------------------ *
     * Hooks a host component may override
     * ------------------------------------------------------------------ */

    abstract protected function viewType(): ViewType;

    /**
     * @return array<array-key, mixed>|null
     */
    protected function savedSorts(): ?array
    {
        return null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    protected function savedColumns(): ?array
    {
        return null;
    }

    protected function savedGroupBy(): ?string
    {
        return null;
    }

    protected function restoreSavedArrangement(SavedView $view): void
    {
        // The board has no columns or sorts of its own to restore; the list overrides this.
    }

    /**
     * Filters changed: the derived data is stale and page 2 of the old result set is not
     * page 2 of the new one.
     */
    protected function afterFilterChange(): void
    {
        if (method_exists($this, 'resetPage')) {
            $this->resetPage();
        }

        $this->forgetTaskCaches();
    }

    protected function forgetTaskCaches(): void
    {
        // Overridden where a component caches rows; the trait itself caches only options.
    }

    /* ------------------------------------------------------------------ *
     * Livewire hooks
     * ------------------------------------------------------------------ */

    public function updatedSearch(): void
    {
        $this->appliedViewId = null;
        $this->afterFilterChange();
    }

    public function updatedOverdueOnly(): void
    {
        $this->appliedViewId = null;
        $this->afterFilterChange();
    }

    public function updatedUnassignedOnly(): void
    {
        $this->appliedViewId = null;
        $this->afterFilterChange();
    }

    public function updatedIncludeCompleted(): void
    {
        $this->appliedViewId = null;
        $this->afterFilterChange();
    }

    /* ------------------------------------------------------------------ *
     * Coercion
     * ------------------------------------------------------------------ */

    /**
     * @param array<array-key, mixed>|mixed $values
     * @return list<int>
     */
    private function intList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ids = [];

        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function priorityValues(): array
    {
        $values = [];

        foreach ($this->priorities as $priority) {
            $case = Priority::tryFrom((string) $priority);

            if ($case instanceof Priority) {
                $values[] = $case->value;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The number inside a search term: "42", "WEB-42" and "web-42" all mean task 42.
     */
    private function numberIn(string $term): ?int
    {
        if (ctype_digit($term)) {
            return (int) $term;
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9]{0,11}-(\d+)$/', $term, $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }
}
