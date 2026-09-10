<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use App\Enums\WorkspaceRole;
use App\Livewire\App\Projects\Concerns\FavouritesProjects;
use App\Models\AiSetting;
use App\Models\Favorite;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The portfolio: every project this person can see, in one of two densities.
 *
 * Grid and list are the same query rendered twice, and the choice is remembered per user
 * rather than per URL — somebody who works in the list does not want a colleague's shared
 * link to switch them to cards. The filters are the opposite: they live in the URL, because
 * "the at-risk client projects due this quarter" is exactly the thing people paste to
 * each other.
 *
 * Every count on a card is an aggregate on the one paginated query. A grid of fifty cards
 * asking each project how many tasks it has would be fifty-one queries, and the lazy-load
 * guard would (rightly) refuse the first of them.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use FavouritesProjects;
    use WithPagination;

    public const DISPLAY_GRID = 'grid';

    public const DISPLAY_LIST = 'list';

    public Workspace $workspace;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $health = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(as: 'member', except: '')]
    public string $memberId = '';

    /** active · archived · all */
    #[Url(except: 'active')]
    public string $archived = 'active';

    /** recent · name · progress · target · health · created */
    #[Url(except: 'recent')]
    public string $sort = 'recent';

    /**
     * Remembered rather than routed. `#[Session]` keys per authenticated session, which is
     * the closest thing to a per-user preference that costs no column.
     */
    #[Session(key: 'planvio.projects.display')]
    public string $display = self::DISPLAY_GRID;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('viewAny', [Project::class, $workspace]);

        $this->workspace = $workspace;
    }

    /* ------------------------------------------------------------------ *
     * Interaction
     * ------------------------------------------------------------------ */

    /**
     * Any filter change puts the reader back on page one. Landing on page 4 of a result set
     * that now has two pages is the classic way a filtered list looks empty when it is not.
     */
    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'health', 'type', 'memberId', 'archived', 'sort'], true)) {
            $this->resetPage();
        }
    }

    public function setDisplay(string $display): void
    {
        $this->display = $display === self::DISPLAY_LIST ? self::DISPLAY_LIST : self::DISPLAY_GRID;
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'health', 'type', 'memberId', 'archived', 'sort']);
        $this->resetPage();
    }

    /**
     * Star or unstar one row.
     *
     * The id arrives from the browser, so it is resolved through the visibility scope before
     * anything is written and then run past the policy — the scope narrows, the policy
     * decides (ARCHITECTURE.md §3).
     */
    public function toggleFavouriteFor(int $projectId): void
    {
        $project = $this->visible()->whereKey($projectId)->first();

        abort_if($project === null, 404);

        $this->authorize('view', $project);

        $starred = $this->setFavourite($project);

        unset($this->favouriteIds);

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: $starred
                ? __('“:project” added to your favourites.', ['project' => $project->name])
                : __('“:project” removed from your favourites.', ['project' => $project->name]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * @return LengthAwarePaginator<int, Project>
     */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $today = Carbon::today()->toDateString();

        $query = $this->visible()
            ->with([
                'status:id,name,color,category',
                'manager:id,name,avatar_path',
                'members' => fn ($relation) => $relation->select(['users.id', 'users.name', 'users.avatar_path']),
            ])
            ->withCount([
                'tasks',
                'tasks as completed_tasks_count' => fn (Builder $tasks): Builder => $tasks
                    ->whereHas('status', fn (Builder $status): Builder => $status->where('is_completed', true)),
                'tasks as overdue_tasks_count' => fn (Builder $tasks): Builder => $tasks
                    ->whereNull('tasks.completed_at')
                    ->whereNotNull('tasks.due_date')
                    ->where('tasks.due_date', '<', $today),
                'milestones',
            ]);

        $this->applyFilters($query);
        $this->applySort($query);

        return $query->paginate((int) config('planvio.pagination.list', 50));
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function favouriteIds(): array
    {
        return Favorite::query()
            ->forUser((int) auth()->id())
            ->ofType((new Project)->getMorphClass())
            ->pluck('favoritable_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * Whether the workspace holds any project at all for this person, regardless of filters.
     *
     * It is what tells "you have nothing yet" apart from "nothing matched", and those two
     * empty states want completely different words and a different next action.
     */
    #[Computed]
    public function hasAnyProject(): bool
    {
        return $this->visible()->exists();
    }

    /**
     * @return EloquentCollection<int, ProjectStatus>
     */
    #[Computed]
    public function statuses(): EloquentCollection
    {
        return ProjectStatus::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->select(['id', 'name', 'color', 'category', 'position'])
            ->ordered()
            ->get();
    }

    /**
     * The people a project can be filtered by: workspace members, guests excluded.
     *
     * A guest is only ever in the projects they were explicitly added to, so offering them
     * here would mostly produce an empty result and would also leak the guest list to
     * everyone who can open this page.
     *
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function people(): EloquentCollection
    {
        return User::query()
            ->select(['users.id', 'users.name', 'users.avatar_path'])
            ->join('workspace_members', 'workspace_members.user_id', '=', 'users.id')
            ->where('workspace_members.workspace_id', $this->workspace->getKey())
            ->where('workspace_members.role', '!=', WorkspaceRole::Guest->value)
            ->orderBy('users.name')
            ->get();
    }

    /**
     * Whether "Create with AI" is worth offering.
     *
     * Both halves matter: the person needs `ai.use`, and the installation needs a usable
     * provider with the kill switch clear. An entry point that opens a panel which then
     * says "AI is off" is worse than no entry point (ARCHITECTURE.md §7.7).
     */
    #[Computed]
    public function aiAvailable(): bool
    {
        return Gate::allows('ai.use', $this->workspace)
            && AiSetting::forWorkspace($this->workspace)?->isUsable() === true;
    }

    #[Computed]
    public function activeFilterCount(): int
    {
        return count(array_filter([
            $this->search !== '',
            $this->status !== '',
            $this->health !== '',
            $this->type !== '',
            $this->memberId !== '',
            $this->archived !== 'active',
        ]));
    }

    public function render(): View
    {
        return view('livewire.app.projects.index')->title(__('Projects'));
    }

    /* ------------------------------------------------------------------ *
     * Query building
     * ------------------------------------------------------------------ */

    /**
     * The tenant-scoped, permission-scoped base every read on this screen starts from.
     *
     * `workspace_id` is pinned explicitly rather than relying on the ambient scope: the
     * scope is convenience, the column and the policy are the controls (ARCHITECTURE.md §3).
     *
     * @return Builder<Project>
     */
    private function visible(): Builder
    {
        return Project::query()
            ->where('projects.workspace_id', $this->workspace->getKey())
            ->visibleTo(auth()->user());
    }

    /**
     * @param Builder<Project> $query
     */
    private function applyFilters(Builder $query): void
    {
        $term = trim($this->search);

        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $query->where(function (Builder $matches) use ($like): void {
                $matches
                    ->where('projects.name', 'like', $like)
                    ->orWhere('projects.key', 'like', $like)
                    ->orWhere('projects.client_name', 'like', $like);
            });
        }

        if ($this->status !== '' && ctype_digit($this->status)) {
            $query->where('projects.status_id', (int) $this->status);
        }

        if ($health = ProjectHealth::tryFrom($this->health)) {
            $query->where('projects.health', $health->value);
        }

        if ($type = ProjectType::tryFrom($this->type)) {
            $query->where('projects.type', $type->value);
        }

        if ($this->memberId !== '' && ctype_digit($this->memberId)) {
            $memberId = (int) $this->memberId;

            $query->where(function (Builder $involving) use ($memberId): void {
                $involving
                    ->where('projects.manager_id', $memberId)
                    ->orWhere('projects.owner_id', $memberId)
                    ->orWhereHas('members', fn (Builder $member): Builder => $member->whereKey($memberId));
            });
        }

        match ($this->archived) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };
    }

    /**
     * @param Builder<Project> $query
     */
    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'name' => $query->orderBy('projects.name'),
            'progress' => $query->orderByDesc('projects.progress')->orderBy('projects.name'),
            // Undated projects sort last rather than first: a list of deadlines that opens
            // with the projects that have none is a list nobody reads twice.
            'target' => $query
                ->orderByRaw('case when projects.target_date is null then 1 else 0 end')
                ->orderBy('projects.target_date')
                ->orderBy('projects.name'),
            // A CASE rather than FIELD(): the same ordering has to hold on SQLite.
            'health' => $query
                ->orderByRaw('case projects.health when ? then 0 when ? then 1 else 2 end', [
                    ProjectHealth::OffTrack->value,
                    ProjectHealth::AtRisk->value,
                ])
                ->orderBy('projects.name'),
            'created' => $query->orderByDesc('projects.created_at'),
            default => $query->orderByDesc('projects.updated_at'),
        };

        $query->orderBy('projects.id');
    }
}
