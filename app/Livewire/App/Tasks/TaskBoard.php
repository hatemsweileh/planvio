<?php

declare(strict_types=1);

namespace App\Livewire\App\Tasks;

use App\Actions\Tasks\MoveTask;
use App\Enums\ViewType;
use App\Livewire\App\Concerns\FiltersTasks;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Kanban board: one column per task status, cards ordered by their fractional position.
 *
 * The drop is the interesting part. The browser reports *which two cards the card landed
 * between*, never an index — an index is a claim about the whole column, computed from a
 * board that may be seconds stale, and honouring it would silently reshuffle cards nobody
 * touched. {@see MoveTask} verifies both anchors against the database and computes the
 * midpoint server-side, so the worst a stale board can do is land the card next to the card
 * it was actually dropped next to.
 *
 * Dragging is never the only way to move a card. Every card carries a status menu that goes
 * through the same {@see MoveTask()} endpoint, so the board is usable from the keyboard, on
 * a phone, and by anyone who cannot drag.
 *
 * Columns load one page of cards each — `planvio.pagination.board_column` — and grow on
 * demand. A project with four thousand tasks must not become four thousand DOM nodes.
 */
#[Layout('layouts.app')]
final class TaskBoard extends Component
{
    use FiltersTasks;

    public Workspace $workspace;

    public Project $project;

    /**
     * How many cards each column has asked for, keyed by status id. Absent means one page.
     *
     * @var array<int, int>
     */
    public array $limits = [];

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', [Task::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    public function render(): View
    {
        return view('livewire.app.tasks.task-board')
            ->title($this->project->name.' · '.__('Board'));
    }

    /* ------------------------------------------------------------------ *
     * The board
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function columns(): EloquentCollection
    {
        return $this->statusOptions;
    }

    /**
     * How many filtered tasks sit in each column — the whole column, not the loaded page.
     *
     * One grouped query rather than one per column: the header count is the number people
     * actually read, and paying a query for each of seven of them is how a board gets slow.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function counts(): array
    {
        // Dropped to the base query on purpose: an Eloquent `pluck` would rewrite the
        // select list and take the aggregate with it.
        $rows = $this->filteredTasks()
            ->reorder()
            ->toBase()
            ->select('tasks.status_id')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('tasks.status_id')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->status_id] = (int) $row->aggregate;
        }

        return $counts;
    }

    /**
     * The cards currently on the board, keyed by status id.
     *
     * @return array<int, EloquentCollection<int, Task>>
     */
    #[Computed]
    public function cards(): array
    {
        $cards = [];

        foreach ($this->columns as $status) {
            $statusId = (int) $status->getKey();

            $tasks = $this->filteredTasks()
                ->where('tasks.status_id', $statusId)
                ->with([
                    'assignee',
                    'tags',
                    'subtasks:id,parent_id,completed_at',
                ])
                ->withCount([
                    'comments',
                    'attachments',
                    'checklistItems',
                    'checklistItems as checklist_items_done_count' => static fn (Builder $q) => $q->where('is_done', true),
                ])
                ->orderBy('tasks.position')
                ->orderBy('tasks.id')
                ->limit($this->limitFor($statusId))
                ->get();

            foreach ($tasks as $task) {
                $task->setRelation('project', $this->project);
                $task->setRelation('status', $status);
            }

            $cards[$statusId] = $tasks;
        }

        return $cards;
    }

    public function limitFor(int $statusId): int
    {
        $page = (int) config('planvio.pagination.board_column', 25);

        return max($page, (int) ($this->limits[$statusId] ?? $page));
    }

    public function hasMore(int $statusId): bool
    {
        return ($this->counts[$statusId] ?? 0) > $this->limitFor($statusId);
    }

    public function loadMore(int $statusId): void
    {
        $page = (int) config('planvio.pagination.board_column', 25);

        $this->limits[$statusId] = $this->limitFor($statusId) + $page;

        unset($this->cards);
    }

    /* ------------------------------------------------------------------ *
     * Moving a card
     * ------------------------------------------------------------------ */

    /**
     * The drop endpoint, shared by the drag handler and by the status menu on every card.
     *
     * `$beforeTaskId` is the card immediately above the drop and `$afterTaskId` the one
     * immediately below; either may be null at the ends of a column. Nothing here trusts
     * them beyond passing them on — {@see MoveTask} re-reads both from the database before
     * it computes a position.
     */
    public function moveTask(int $taskId, int $statusId, ?int $beforeTaskId = null, ?int $afterTaskId = null): void
    {
        $task = Task::query()->forProject($this->project)->whereKey($taskId)->first();
        $status = TaskStatus::query()->forProject($this->project)->whereKey($statusId)->first();

        if (! $task instanceof Task || ! $status instanceof TaskStatus) {
            // The board the browser is holding no longer matches the database. Redrawing is
            // the honest answer; it puts the card back where it really is.
            unset($this->cards, $this->counts);

            return;
        }

        $task->setRelation('project', $this->project);
        $task->setRelation('workspace', $this->workspace);

        $this->authorize('move', $task);

        $from = (int) $task->status_id;

        try {
            app(MoveTask::class)($task, $status, $beforeTaskId, $afterTaskId, $this->user());
        } catch (DomainException $exception) {
            unset($this->cards, $this->counts);

            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        unset($this->cards, $this->counts);

        $this->dispatch('task-updated', taskId: $taskId);

        if ($from !== (int) $status->getKey()) {
            $this->dispatch(
                'planvio-notify',
                type: 'success',
                message: __(':key moved to :status', ['key' => $task->key, 'status' => $status->name]),
            );
        }
    }

    /**
     * The keyboard route: put the card at the end of the chosen column.
     *
     * Passing no anchors is what makes this equivalent to a drop on empty space rather than
     * a claim about neighbours the person never saw.
     */
    public function moveToStatus(int $taskId, int $statusId): void
    {
        $this->moveTask($taskId, $statusId, null, null);
    }

    /* ------------------------------------------------------------------ *
     * Cross-component refresh
     * ------------------------------------------------------------------ */

    #[On('task-updated')]
    #[On('task-created')]
    #[On('task-deleted')]
    public function refreshBoard(?int $taskId = null): void
    {
        $this->forgetTaskCaches();
    }

    /* ------------------------------------------------------------------ *
     * FiltersTasks hooks
     * ------------------------------------------------------------------ */

    protected function viewType(): ViewType
    {
        return ViewType::Board;
    }

    protected function afterFilterChange(): void
    {
        // A narrower board is a shorter board: keeping "show 75" from the old filter set
        // would load three pages of a column that now holds four cards.
        $this->limits = [];

        $this->forgetTaskCaches();
    }

    protected function forgetTaskCaches(): void
    {
        unset($this->cards, $this->counts, $this->columns, $this->statusOptions);
    }

    private function user(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
