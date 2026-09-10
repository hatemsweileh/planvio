<?php

declare(strict_types=1);

namespace App\Livewire\App\Home;

use App\Enums\AiRunStatus;
use App\Livewire\App\Concerns\FormatsWorkDates;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkloadRow;
use App\Services\WorkloadService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The workspace dashboard — the first screen of the morning.
 *
 * The whole page is a fixed number of queries whatever the size of the workspace. Nothing
 * here loops over a collection issuing a query per row: counts come from grouped aggregates
 * and `withCount`, lists come from capped selects, and every relation a row needs is eager
 * loaded. Twelve queries render the page for a workspace of five projects and twelve for a
 * workspace of five hundred, which is the only property that matters on shared hosting.
 *
 * Two rules shape the layout as much as the data does:
 *
 *   - **Counts and rows are different things.** The number on a heading comes from an
 *     aggregate over everything; the rows beneath it are a capped sample. A dashboard that
 *     counted the rows it happened to fetch would quietly under-report the moment somebody
 *     had more than a screenful of late work.
 *   - **Generated text never sits in the same frame as recorded figures.** The AI card has
 *     its own surface, its own border treatment and an explicit "AI analysis" tag, and it
 *     shows sentences the agent actually wrote — nothing is synthesised here and dressed up
 *     as a model's opinion.
 *
 * The activity feed is a child component ({@see ActivityFeed}) rather than a section of this
 * one, because it polls. Polling this component would re-run every aggregate on the page
 * every thirty seconds to refresh eight rows.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    use FormatsWorkDates;

    /** How far ahead the deadline strip looks. */
    private const HORIZON_DAYS = 14;

    /** Rows shown under "Needs attention" before it defers to My Tasks. */
    private const ATTENTION_ROWS = 8;

    /** Deadlines pulled into the strip. Days without one are not drawn at all. */
    private const HORIZON_ROWS = 12;

    private const PROJECT_CARDS = 6;

    private const MENTION_ROWS = 3;

    private const INSIGHT_ROWS = 3;

    private const WORKLOAD_ROWS = 6;

    public Workspace $workspace;

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        $this->workspace = $workspace;
    }

    /* ------------------------------------------------------------------ *
     * Time
     * ------------------------------------------------------------------ */

    public function greeting(): string
    {
        $name = trim((string) (auth()->user()?->name ?? ''));
        $first = $name === '' ? null : explode(' ', $name)[0];
        $hour = CarbonImmutable::now($this->timezone)->hour;

        $greeting = match (true) {
            $hour < 12 => $first === null ? __('Good morning') : __('Good morning, :name', ['name' => $first]),
            $hour < 18 => $first === null ? __('Good afternoon') : __('Good afternoon, :name', ['name' => $first]),
            default => $first === null ? __('Good evening') : __('Good evening, :name', ['name' => $first]),
        };

        return $greeting;
    }

    /* ------------------------------------------------------------------ *
     * My numbers — one aggregate, five figures
     * ------------------------------------------------------------------ */

    /**
     * @return array{open: int, overdue: int, today: int, week: int, completed_week: int}
     */
    #[Computed]
    public function mine(): array
    {
        $empty = ['open' => 0, 'overdue' => 0, 'today' => 0, 'week' => 0, 'completed_week' => 0];

        $user = $this->user();

        if (! $user instanceof User) {
            return $empty;
        }

        $today = $this->today();

        // Half-open bounds throughout: MySQL stores a `date` column bare while SQLite keeps
        // the cast's "Y-m-d H:i:s", so an inclusive upper bound would drop the last day.
        $row = Task::query()
            ->forWorkspace($this->workspace)
            ->assignedTo($user)
            ->selectRaw('count(case when tasks.completed_at is null then 1 end) as open_count')
            ->selectRaw(
                'count(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date < ? then 1 end) as overdue_count',
                [$today->toDateString()],
            )
            ->selectRaw(
                'count(case when tasks.completed_at is null and tasks.due_date >= ?'
                .' and tasks.due_date < ? then 1 end) as today_count',
                [$today->toDateString(), $today->addDay()->toDateString()],
            )
            ->selectRaw(
                'count(case when tasks.completed_at is null and tasks.due_date >= ?'
                .' and tasks.due_date < ? then 1 end) as week_count',
                [$today->addDay()->toDateString(), $today->addDays(8)->toDateString()],
            )
            ->selectRaw(
                'count(case when tasks.completed_at >= ? then 1 end) as completed_week_count',
                [$today->subDays(7)->toDateTimeString()],
            )
            ->toBase()
            ->first();

        if ($row === null) {
            return $empty;
        }

        return [
            'open' => (int) $row->open_count,
            'overdue' => (int) $row->overdue_count,
            'today' => (int) $row->today_count,
            'week' => (int) $row->week_count,
            'completed_week' => (int) $row->completed_week_count,
        ];
    }

    /**
     * The one line under the greeting. Says what is true, in the order it matters, and
     * says nothing rather than padding when there is nothing to say.
     */
    public function stateOfPlay(): string
    {
        $mine = $this->mine;
        $mentions = $this->unreadMentionCount();

        if ($mine['open'] === 0 && $mentions === 0) {
            return __('Nothing is assigned to you right now — a good moment to pick up something new.');
        }

        $parts = [];

        if ($mine['overdue'] > 0) {
            $parts[] = trans_choice(
                '{1}:count task overdue|[2,*]:count tasks overdue',
                $mine['overdue'],
                ['count' => $mine['overdue']],
            );
        }

        if ($mine['today'] > 0) {
            $parts[] = trans_choice(
                '{1}:count due today|[2,*]:count due today',
                $mine['today'],
                ['count' => $mine['today']],
            );
        }

        if ($mentions > 0) {
            $parts[] = trans_choice(
                '{1}:count mention waiting|[2,*]:count mentions waiting',
                $mentions,
                ['count' => $mentions],
            );
        }

        // Named before the fallback below can claim nothing is due: work landing inside the
        // week is exactly what that sentence would otherwise deny.
        if ($parts === [] && $mine['week'] > 0) {
            $parts[] = trans_choice(
                '{1}:count due in the next seven days|[2,*]:count due in the next seven days',
                $mine['week'],
                ['count' => $mine['week']],
            );
        }

        if ($parts === []) {
            return __(':open open, nothing due before :date.', [
                'open' => $mine['open'],
                'date' => $this->today()->addDays(8)->translatedFormat('j M'),
            ]);
        }

        return implode(' · ', $parts).'.';
    }

    /* ------------------------------------------------------------------ *
     * Needs attention
     * ------------------------------------------------------------------ */

    /**
     * Two capped selects — what is late or due today, and what is due inside the horizon —
     * followed by a single load of their projects. Splitting the window in two is what
     * keeps a wall of overdue work from crowding the deadline strip out of the page.
     *
     * @return array{attention: EloquentCollection<int, Task>, horizon: EloquentCollection<int, Task>}
     */
    #[Computed]
    public function myTasks(): array
    {
        $user = $this->user();

        /** @var EloquentCollection<int, Task> $none */
        $none = new EloquentCollection;

        if (! $user instanceof User) {
            return ['attention' => $none, 'horizon' => $none];
        }

        $today = $this->today();
        $tomorrow = $today->addDay()->toDateString();
        $horizon = $today->addDays(self::HORIZON_DAYS)->toDateString();

        $attention = $this->assignedQuery($user)
            ->where('tasks.due_date', '<', $tomorrow)
            ->orderBy('tasks.due_date')
            ->orderBy('tasks.id')
            ->limit(self::ATTENTION_ROWS)
            ->get();

        $upcoming = $this->assignedQuery($user)
            ->where('tasks.due_date', '>=', $tomorrow)
            ->where('tasks.due_date', '<', $horizon)
            ->orderBy('tasks.due_date')
            ->orderBy('tasks.id')
            ->limit(self::HORIZON_ROWS)
            ->get();

        // One load for both sets: `load()` writes the relation onto the model instances, and
        // the merged collection holds the same instances, so each set comes back hydrated.
        $attention->merge($upcoming)->load(['project:id,name,slug,key,color']);

        return ['attention' => $attention, 'horizon' => $upcoming];
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function overdueTasks(): Collection
    {
        $today = $this->today();

        return $this->myTasks['attention']
            ->filter(fn (Task $task): bool => $task->due_date !== null
                && CarbonImmutable::instance($task->due_date)->startOfDay()->lessThan($today))
            ->values();
    }

    /**
     * @return Collection<int, Task>
     */
    #[Computed]
    public function dueTodayTasks(): Collection
    {
        $today = $this->today();

        return $this->myTasks['attention']
            ->filter(fn (Task $task): bool => $task->due_date !== null
                && CarbonImmutable::instance($task->due_date)->startOfDay()->equalTo($today))
            ->values();
    }

    /**
     * Unread mentions, rendered straight out of the stored payload. The inbox row is written
     * at send time precisely so a dashboard never has to join three tables to draw a line.
     *
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function mentions(): Collection
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return new Collection;
        }

        return $this->notificationQuery($user)
            ->whereNull('read_at')
            ->where('category', 'comment.mentioned')
            ->orderByDesc('created_at')
            ->limit(self::MENTION_ROWS)
            ->get();
    }

    /**
     * Unread counts per category, in one grouped pass.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function unreadByCategory(): array
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return [];
        }

        $counts = [];

        $rows = $this->notificationQuery($user)
            ->whereNull('read_at')
            ->groupBy('category')
            ->selectRaw('category, count(*) as total')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $counts[(string) ($row->category ?? '')] = (int) $row->total;
        }

        return $counts;
    }

    public function unreadMentionCount(): int
    {
        return $this->unreadByCategory['comment.mentioned'] ?? 0;
    }

    public function needsAttention(): bool
    {
        return $this->overdueTasks->isNotEmpty()
            || $this->dueTodayTasks->isNotEmpty()
            || $this->mentions->isNotEmpty();
    }

    /* ------------------------------------------------------------------ *
     * Projects
     * ------------------------------------------------------------------ */

    /**
     * Active projects the viewer can see, most in need of attention first.
     *
     * Three aggregates ride along as `withCount` sub-selects so a card can show open,
     * overdue and completed counts without a query per card, and the member list is a single
     * eager load rather than one per project.
     *
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function projects(): EloquentCollection
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return new EloquentCollection;
        }

        $today = $this->today()->toDateString();

        return Project::query()
            ->forWorkspace($this->workspace)
            ->active()
            ->visibleTo($user)
            ->withCount([
                'tasks as open_tasks_count' => static fn (Builder $query): Builder => $query
                    ->whereNull('tasks.completed_at'),
                'tasks as completed_tasks_count' => static fn (Builder $query): Builder => $query
                    ->whereNotNull('tasks.completed_at'),
                'tasks as overdue_tasks_count' => static fn (Builder $query): Builder => $query
                    ->whereNull('tasks.completed_at')
                    ->whereNotNull('tasks.due_date')
                    ->where('tasks.due_date', '<', $today),
            ])
            ->with([
                'members' => static fn ($query) => $query
                    ->select(['users.id', 'users.name', 'users.avatar_path'])
                    ->orderBy('users.name'),
            ])
            // Health first, then the count of late work, then the nearest target date with
            // undated projects last. Ordering by a select alias is portable on both drivers.
            ->orderByRaw("case projects.health when 'off_track' then 0 when 'at_risk' then 1 else 2 end")
            ->orderByDesc('overdue_tasks_count')
            ->orderByRaw('case when projects.target_date is null then 1 else 0 end')
            ->orderBy('projects.target_date')
            ->orderBy('projects.name')
            ->limit(self::PROJECT_CARDS)
            ->get();
    }

    /* ------------------------------------------------------------------ *
     * The next fortnight
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function milestones(): EloquentCollection
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return new EloquentCollection;
        }

        return Milestone::query()
            ->forWorkspace($this->workspace)
            ->upcoming()
            ->where('milestones.due_date', '<', $this->today()->addDays(self::HORIZON_DAYS)->toDateString())
            ->whereHas('project', static fn (Builder $query): Builder => $query->active()->visibleTo($user))
            ->with(['project:id,name,slug,key,color'])
            ->limit(self::HORIZON_ROWS)
            ->get();
    }

    /**
     * The deadline strip: one cell per day that actually has something on it, today first.
     *
     * Days with nothing scheduled are omitted rather than drawn empty — fourteen cells of
     * which three carry content is a chart of the calendar, not of the work.
     *
     * @return list<array{date: CarbonImmutable, tasks: list<Task>, milestones: list<Milestone>}>
     */
    #[Computed]
    public function horizon(): array
    {
        $days = [];

        foreach ($this->dueTodayTasks as $task) {
            $this->pushDay($days, $task->due_date, 'tasks', $task);
        }

        foreach ($this->myTasks['horizon'] as $task) {
            $this->pushDay($days, $task->due_date, 'tasks', $task);
        }

        foreach ($this->milestones as $milestone) {
            $this->pushDay($days, $milestone->due_date, 'milestones', $milestone);
        }

        ksort($days);

        return array_values($days);
    }

    /* ------------------------------------------------------------------ *
     * Team workload
     * ------------------------------------------------------------------ */

    public function canSeeWorkload(): bool
    {
        return Gate::allows('reports.view', $this->workspace);
    }

    /**
     * Two queries whatever the size of the team — the aggregate and the roster.
     *
     * @return list<WorkloadRow>
     */
    #[Computed]
    public function workload(): array
    {
        if (! $this->canSeeWorkload()) {
            return [];
        }

        $rows = app(WorkloadService::class)->forWorkspace($this->workspace, null, $this->today());

        return array_slice(
            array_values(array_filter(
                $rows,
                static fn (WorkloadRow $row): bool => $row->hasWork() || ! $row->isUnassigned(),
            )),
            0,
            self::WORKLOAD_ROWS,
        );
    }

    /**
     * The busiest row's open count, used to scale the bars. Never zero, so the divisor is
     * always safe.
     */
    public function workloadPeak(): int
    {
        $peak = 1;

        foreach ($this->workload as $row) {
            $peak = max($peak, $row->open);
        }

        return $peak;
    }

    /* ------------------------------------------------------------------ *
     * AI analysis
     * ------------------------------------------------------------------ */

    /**
     * The AI settings governing this workspace — its own row, or the global default it
     * inherits. Fetched with the provider attached because `isUsable()` reads it, and a lazy
     * load there would throw under the no-lazy-loading guard.
     */
    #[Computed]
    public function aiSetting(): ?AiSetting
    {
        if (! Gate::allows('ai.use', $this->workspace)) {
            return null;
        }

        return AiSetting::query()
            ->with('provider')
            ->where(function (Builder $query): void {
                $query->where('workspace_id', $this->workspace->getKey())->orWhereNull('workspace_id');
            })
            ->orderByRaw('case when workspace_id is null then 1 else 0 end')
            ->first();
    }

    public function aiAvailable(): bool
    {
        return $this->aiSetting?->isUsable() === true;
    }

    /**
     * Summaries the agent actually wrote, newest first.
     *
     * Nothing is generated here and nothing is paraphrased: these are the `summary` values
     * stored on finished runs. If the agent has never run, the card says so and offers to
     * start one — it does not invent an observation to fill the space.
     *
     * @return EloquentCollection<int, AiRun>
     */
    #[Computed]
    public function insights(): EloquentCollection
    {
        $user = $this->user();

        if (! $this->aiAvailable() || ! $user instanceof User) {
            return new EloquentCollection;
        }

        return AiRun::query()
            ->forWorkspace($this->workspace)
            ->whereNotNull('summary')
            ->where('summary', '!=', '')
            ->withStatus([AiRunStatus::Succeeded, AiRunStatus::Partial])
            ->where(function (Builder $query) use ($user): void {
                $query->whereNull('project_id')
                    ->orWhereHas('project', static fn (Builder $inner): Builder => $inner->visibleTo($user));
            })
            ->with(['project:id,name,slug,key,color'])
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->limit(self::INSIGHT_ROWS)
            ->get();
    }

    /* ------------------------------------------------------------------ *
     * Render
     * ------------------------------------------------------------------ */

    public function render(): View
    {
        return view('livewire.app.home.index')->title(__('Home'));
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * Open, dated work assigned to this person, in a project they are allowed to see.
     *
     * The project check is not redundant with the workspace scope: a guest is a member of
     * the workspace but only of the projects they were added to, and a task assigned to them
     * and then moved elsewhere must not surface here.
     *
     * @return Builder<Task>
     */
    private function assignedQuery(User $user): Builder
    {
        return Task::query()
            ->forWorkspace($this->workspace)
            ->assignedTo($user)
            ->open()
            ->whereNotNull('tasks.due_date')
            ->whereHas('project', static fn (Builder $query): Builder => $query->visibleTo($user));
    }

    /**
     * This person's notifications for this workspace, plus the account-level ones that
     * belong to no workspace — a security notice must not be invisible because of which
     * tenant happens to be open.
     *
     * @return Builder<DatabaseNotification>
     */
    private function notificationQuery(User $user)
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where(function ($query): void {
                $query->where('workspace_id', $this->workspace->getKey())->orWhereNull('workspace_id');
            });
    }

    /**
     * @param array<string, array{date: CarbonImmutable, tasks: list<Task>, milestones: list<Milestone>}> $days
     */
    private function pushDay(array &$days, mixed $date, string $bucket, mixed $item): void
    {
        if (! $date instanceof \DateTimeInterface) {
            return;
        }

        $day = CarbonImmutable::instance($date)->startOfDay();
        $key = $day->toDateString();

        $days[$key] ??= ['date' => $day, 'tasks' => [], 'milestones' => []];
        $days[$key][$bucket][] = $item;
    }
}
