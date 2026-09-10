<?php

declare(strict_types=1);

namespace App\Livewire\App\Calendar\Concerns;

use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\UpdateTask;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChartPalette;
use App\Support\Formats;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Throwable;

/**
 * The calendar, shared by the workspace view and the single-project one.
 *
 * ## Dates never move
 *
 * `tasks.due_date`, `milestones.due_date`, `projects.start_date` and `projects.target_date`
 * are `date` columns: a day, with no instant and no zone attached. The one bug a calendar
 * cannot afford is drawing one of them a day out, and every such bug has the same cause — a
 * date-only value converted through a timezone.
 *
 * So nothing here converts. A stored day reaches the grid through `format('Y-m-d')` on the
 * value Eloquent read back, which returns the characters the column holds whatever the
 * application timezone happens to be. The workspace's timezone is used for exactly one
 * thing: deciding which day *today* is, because that genuinely differs between Auckland and
 * Los Angeles. Which column the week opens in comes from {@see Formats::weekStartsOn()} —
 * the workspace's setting, unless the language being read has one of its own — and it
 * decides nothing else.
 *
 * ## The queries
 *
 * Three, bounded: tasks, milestones, and the project date markers. Each is capped at
 * {@see self::MAX_EVENTS} and each is half-open on the date bounds — `>= from` and
 * `< to + 1 day` — for the reason the model scopes give: MySQL hands a `date` column back
 * bare while SQLite hands back the cast's `Y-m-d H:i:s`, so an inclusive upper bound
 * silently drops the last day of the range on one of the two engines.
 *
 * Visibility is enforced twice, as ARCHITECTURE.md §3 requires: the workspace scope narrows
 * the table, and `Project::visibleTo()` narrows again to the projects this person may
 * actually open, so a guest's calendar shows the projects they were invited to and nothing
 * else. Every write still goes through the task's own policy.
 */
trait BuildsCalendar
{
    /** How many dated records one screen of calendar will draw before it stops counting. */
    private const MAX_EVENTS = 600;

    /** Chips shown in a month cell before the rest collapse into a "+N more" control. */
    private const MAX_PER_CELL = 3;

    #[Url(as: 'view', except: 'month')]
    public string $mode = 'month';

    /** The day the view is centred on, `Y-m-d`. Empty means today, in the workspace's zone. */
    #[Url(as: 'on', except: '')]
    public string $anchor = '';

    #[Url(as: 'project', except: '')]
    public string $projectFilter = '';

    #[Url(as: 'assignee', except: '')]
    public string $assigneeFilter = '';

    /** `all`, `tasks`, `milestones` or `projects`. */
    #[Url(as: 'show', except: 'all')]
    public string $typeFilter = 'all';

    /** The task open in the detail drawer, if any. */
    public ?int $openTaskId = null;

    /**
     * Whether the drawer is showing.
     *
     * Kept as its own flag rather than derived from `$openTaskId` because the drawer closes
     * itself — Escape, a click on the scrim — and has to be able to tell the server so,
     * which it does by writing `false` here.
     */
    public bool $drawerOpen = false;

    /** The date bound to the drawer's reschedule field. */
    public string $rescheduleTo = '';

    /**
     * The project this calendar is locked to, or null for the whole workspace.
     */
    abstract protected function scopedProject(): ?Project;

    abstract protected function calendarWorkspace(): Workspace;

    /* ------------------------------------------------------------------ *
     * Navigation
     * ------------------------------------------------------------------ */

    public function setMode(string $mode): void
    {
        if (! in_array($mode, ['month', 'week', 'day'], true)) {
            return;
        }

        // Switching view keeps the day you were looking at rather than jumping back to
        // today: the anchor is the thing the person has been navigating, and losing it on
        // every zoom change is the most irritating behaviour a calendar can have.
        $this->anchor = $this->anchorDate()->toDateString();
        $this->mode = $mode;

        $this->resetCalendarCache();
    }

    public function goToToday(): void
    {
        $this->anchor = '';
        $this->resetCalendarCache();
    }

    public function goPrevious(): void
    {
        $this->shiftAnchor(-1);
    }

    public function goNext(): void
    {
        $this->shiftAnchor(1);
    }

    public function goToDay(string $date): void
    {
        $day = $this->parseDate($date);

        if ($day === null) {
            return;
        }

        $this->anchor = $day->toDateString();
        $this->mode = 'day';

        $this->resetCalendarCache();
    }

    public function updatedProjectFilter(): void
    {
        $this->resetCalendarCache();
    }

    public function updatedAssigneeFilter(): void
    {
        $this->resetCalendarCache();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetCalendarCache();
    }

    public function clearFilters(): void
    {
        $this->projectFilter = '';
        $this->assigneeFilter = '';
        $this->typeFilter = 'all';

        $this->resetCalendarCache();
    }

    private function shiftAnchor(int $direction): void
    {
        $anchor = $this->anchorDate();

        $this->anchor = match ($this->mode) {
            'day' => $anchor->addDays($direction)->toDateString(),
            'week' => $anchor->addWeeks($direction)->toDateString(),
            default => $anchor->addMonthsNoOverflow($direction)->startOfMonth()->toDateString(),
        };

        $this->resetCalendarCache();
    }

    private function resetCalendarCache(): void
    {
        unset($this->calendar, $this->events, $this->drawerTask);
    }

    /* ------------------------------------------------------------------ *
     * Time, as this workspace keeps it
     * ------------------------------------------------------------------ */

    public function timezone(): string
    {
        $timezone = (string) ($this->calendarWorkspace()->timezone ?? '');

        return $timezone === '' ? (string) config('app.timezone', 'UTC') : $timezone;
    }

    public function weekStartsOn(): int
    {
        return Formats::weekStartsOn($this->calendarWorkspace());
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    public function anchorDate(): CarbonImmutable
    {
        return $this->parseDate($this->anchor) ?? $this->today();
    }

    /**
     * The first and last day the current view shows, inclusive.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function visibleSpan(): array
    {
        $anchor = $this->anchorDate();
        $weekStart = $this->weekStartsOn();

        return match ($this->mode) {
            'day' => [$anchor, $anchor],
            'week' => [$anchor->startOfWeek($weekStart), $anchor->startOfWeek($weekStart)->addDays(6)],
            default => [
                $anchor->startOfMonth()->startOfWeek($weekStart),
                $anchor->endOfMonth()->startOfDay()->startOfWeek($weekStart)->addDays(6),
            ],
        };
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('Y-m-d', $value, $this->timezone());
        } catch (Throwable) {
            return null;
        }

        return $date === false ? null : $date->startOfDay();
    }

    /* ------------------------------------------------------------------ *
     * The grid
     * ------------------------------------------------------------------ */

    /**
     * @return array{
     *     from: string, to: string, title: string, subtitle: string, columns: int,
     *     weekdays: list<array{label: string, short: string, weekend: bool}>,
     *     rows: list<list<array<string, mixed>>>, truncated: bool, total: int
     * }
     */
    #[Computed]
    public function calendar(): array
    {
        [$from, $to] = $this->visibleSpan();

        $columns = $this->mode === 'day' ? 1 : 7;
        $events = $this->events;
        $buckets = $events['buckets'];
        $today = $this->today()->toDateString();
        $month = $this->anchorDate()->format('Y-m');

        $days = [];
        $cursor = $from;

        while ($cursor->lessThanOrEqualTo($to)) {
            $date = $cursor->toDateString();
            $dayEvents = $buckets[$date] ?? [];

            $days[] = [
                'date' => $date,
                'day' => (int) $cursor->format('j'),
                'isFirstOfMonth' => $cursor->format('j') === '1',
                'monthLabel' => $cursor->translatedFormat('M'),
                'inScope' => $this->mode !== 'month' || $cursor->format('Y-m') === $month,
                'isToday' => $date === $today,
                'isWeekend' => in_array((int) $cursor->format('w'), [0, 6], true),
                'weekdayLong' => $cursor->translatedFormat('l'),
                'label' => $cursor->translatedFormat('j F Y'),
                'events' => $dayEvents,
                'visible' => $this->mode === 'month' ? array_slice($dayEvents, 0, self::MAX_PER_CELL) : $dayEvents,
                'overflow' => $this->mode === 'month' ? max(0, count($dayEvents) - self::MAX_PER_CELL) : 0,
            ];

            $cursor = $cursor->addDay();
        }

        $weekStart = $this->weekStartsOn();
        $weekdays = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $from->startOfWeek($weekStart)->addDays($offset);

            $weekdays[] = [
                'label' => $day->translatedFormat('l'),
                'short' => $day->translatedFormat('D'),
                'weekend' => in_array((int) $day->format('w'), [0, 6], true),
            ];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'title' => match ($this->mode) {
                'day' => $from->translatedFormat('l j F Y'),
                'week' => $from->format('Y-m') === $to->format('Y-m')
                    ? $from->translatedFormat('j').' – '.$to->translatedFormat('j F Y')
                    : $from->translatedFormat('j M').' – '.$to->translatedFormat('j M Y'),
                default => $this->anchorDate()->translatedFormat('F Y'),
            },
            'subtitle' => trans_choice(
                '{0}Nothing scheduled|{1}:count scheduled item|[2,*]:count scheduled items',
                $events['total'],
                ['count' => $events['total']],
            ),
            'columns' => $columns,
            'weekdays' => $columns === 7 ? $weekdays : [],
            'rows' => $columns === 7 ? array_chunk($days, 7) : [$days],
            'truncated' => $events['truncated'],
            'total' => $events['total'],
        ];
    }

    /**
     * Every dated record in the visible span, bucketed by the day it belongs to.
     *
     * @return array{buckets: array<string, list<array<string, mixed>>>, total: int, truncated: bool}
     */
    #[Computed]
    public function events(): array
    {
        [$from, $to] = $this->visibleSpan();

        $fromDate = $from->toDateString();
        $toExclusive = $to->addDay()->toDateString();
        $today = $this->today()->toDateString();

        $all = [
            ...$this->taskEvents($fromDate, $toExclusive, $today),
            ...$this->milestoneEvents($fromDate, $toExclusive, $today),
            ...$this->projectEvents($fromDate, $toExclusive),
        ];

        $buckets = [];

        foreach ($all as $event) {
            $buckets[$event['date']][] = $event;
        }

        foreach ($buckets as $date => $events) {
            usort(
                $events,
                static fn (array $a, array $b): int => [$a['order'], $a['title']] <=> [$b['order'], $b['title']],
            );

            $buckets[$date] = $events;
        }

        ksort($buckets);

        return [
            'buckets' => $buckets,
            'total' => count($all),
            'truncated' => count($all) >= self::MAX_EVENTS,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function taskEvents(string $from, string $toExclusive, string $today): array
    {
        if (! in_array($this->typeFilter, ['all', 'tasks'], true)) {
            return [];
        }

        $user = $this->actorOrFail();
        $project = $this->scopedProject();
        $projectFilter = $this->filterId($this->projectFilter);
        $assigneeFilter = $this->filterId($this->assigneeFilter);

        $tasks = Task::query()
            ->forWorkspace($this->calendarWorkspace())
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '>=', $from)
            ->where('tasks.due_date', '<', $toExclusive)
            ->when(
                $project instanceof Project,
                fn (Builder $query): Builder => $query->where('tasks.project_id', $project?->getKey()),
                fn (Builder $query): Builder => $query
                    ->whereHas('project', fn (Builder $sub): Builder => $sub->visibleTo($user))
                    ->when(
                        $projectFilter !== null,
                        fn (Builder $inner): Builder => $inner->where('tasks.project_id', $projectFilter),
                    ),
            )
            ->when(
                $assigneeFilter !== null,
                fn (Builder $query): Builder => $query->where('tasks.assignee_id', $assigneeFilter),
            )
            ->with([
                'project:id,workspace_id,name,key,slug,color',
                'status:id,name,color,category,is_completed',
                'assignee:id,name,avatar_path',
            ])
            ->orderBy('tasks.due_date')
            ->orderBy('tasks.id')
            ->limit(self::MAX_EVENTS)
            ->get();

        $events = [];

        foreach ($tasks as $task) {
            $date = $task->due_date?->format('Y-m-d') ?? $from;
            $completed = $task->is_completed;

            $events[] = [
                'type' => 'task',
                'order' => $completed ? 3 : 1,
                'id' => (int) $task->getKey(),
                'date' => $date,
                'title' => (string) $task->title,
                'reference' => (string) $task->key,
                'color' => ChartPalette::forKey('project:'.$task->project_id),
                'projectName' => $task->project?->name,
                'statusName' => $task->status?->name,
                'statusColor' => $task->status?->color,
                'priorityLabel' => $task->priority?->label(),
                'priorityColor' => $task->priority?->color(),
                'assigneeName' => $task->assignee?->name,
                'completed' => $completed,
                'overdue' => ! $completed && $date < $today,
                'draggable' => $user->can('update', $task),
            ];
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function milestoneEvents(string $from, string $toExclusive, string $today): array
    {
        if (! in_array($this->typeFilter, ['all', 'milestones'], true)) {
            return [];
        }

        $user = $this->actorOrFail();
        $project = $this->scopedProject();
        $projectFilter = $this->filterId($this->projectFilter);
        $assigneeFilter = $this->filterId($this->assigneeFilter);

        $milestones = Milestone::query()
            ->forWorkspace($this->calendarWorkspace())
            ->whereNotNull('milestones.due_date')
            ->where('milestones.due_date', '>=', $from)
            ->where('milestones.due_date', '<', $toExclusive)
            ->when(
                $project instanceof Project,
                fn (Builder $query): Builder => $query->where('milestones.project_id', $project?->getKey()),
                fn (Builder $query): Builder => $query
                    ->whereHas('project', fn (Builder $sub): Builder => $sub->visibleTo($user))
                    ->when(
                        $projectFilter !== null,
                        fn (Builder $inner): Builder => $inner->where('milestones.project_id', $projectFilter),
                    ),
            )
            ->when(
                $assigneeFilter !== null,
                fn (Builder $query): Builder => $query->where('milestones.owner_id', $assigneeFilter),
            )
            ->with(['project:id,workspace_id,name,key,slug,color'])
            ->orderBy('milestones.due_date')
            ->orderBy('milestones.id')
            ->limit(self::MAX_EVENTS)
            ->get();

        $events = [];

        foreach ($milestones as $milestone) {
            $date = $milestone->due_date?->format('Y-m-d') ?? $from;

            $events[] = [
                'type' => 'milestone',
                'order' => 0,
                'id' => (int) $milestone->getKey(),
                'date' => $date,
                'title' => (string) $milestone->name,
                'reference' => $milestone->project?->key,
                'color' => ChartPalette::forKey('project:'.$milestone->project_id),
                'projectName' => $milestone->project?->name,
                'projectSlug' => $milestone->project?->slug,
                'statusName' => $milestone->status?->label(),
                'statusColor' => $milestone->status?->color(),
                'progress' => (int) $milestone->progress,
                'completed' => $milestone->completed_at !== null,
                'overdue' => $milestone->completed_at === null
                    && $milestone->status?->isOpen() === true
                    && $date < $today,
                'draggable' => false,
            ];
        }

        return $events;
    }

    /**
     * Project start and target dates: two markers, not a band.
     *
     * A project's span is the timeline's job. On a calendar the two useful facts are the
     * days themselves — the day work is meant to begin and the day it is meant to be done —
     * and a thirty-day band drawn across a month grid buries every task underneath it.
     *
     * @return list<array<string, mixed>>
     */
    private function projectEvents(string $from, string $toExclusive): array
    {
        if (! in_array($this->typeFilter, ['all', 'projects'], true)) {
            return [];
        }

        $user = $this->actorOrFail();
        $scoped = $this->scopedProject();
        $projectFilter = $this->filterId($this->projectFilter);

        $projects = Project::query()
            ->forWorkspace($this->calendarWorkspace())
            ->when(
                $scoped instanceof Project,
                fn (Builder $query): Builder => $query->whereKey($scoped?->getKey()),
                fn (Builder $query): Builder => $query
                    ->visibleTo($user)
                    ->active()
                    ->when(
                        $projectFilter !== null,
                        fn (Builder $inner): Builder => $inner->whereKey($projectFilter),
                    ),
            )
            ->where(function (Builder $query) use ($from, $toExclusive): void {
                $query
                    ->where(fn (Builder $sub): Builder => $sub
                        ->whereNotNull('projects.start_date')
                        ->where('projects.start_date', '>=', $from)
                        ->where('projects.start_date', '<', $toExclusive))
                    ->orWhere(fn (Builder $sub): Builder => $sub
                        ->whereNotNull('projects.target_date')
                        ->where('projects.target_date', '>=', $from)
                        ->where('projects.target_date', '<', $toExclusive));
            })
            ->orderBy('projects.name')
            ->limit(self::MAX_EVENTS)
            ->get([
                'id', 'workspace_id', 'name', 'key', 'slug', 'color',
                'start_date', 'target_date', 'health', 'progress',
            ]);

        $events = [];

        foreach ($projects as $project) {
            $color = ChartPalette::forKey('project:'.$project->getKey());

            foreach ([['start_date', 'start'], ['target_date', 'target']] as [$column, $kind]) {
                $value = $project->getAttribute($column);

                if ($value === null) {
                    continue;
                }

                $day = $value->format('Y-m-d');

                if ($day < $from || $day >= $toExclusive) {
                    continue;
                }

                $events[] = [
                    'type' => 'project',
                    'kind' => $kind,
                    'order' => $kind === 'start' ? -1 : 4,
                    'id' => (int) $project->getKey(),
                    'date' => $day,
                    'title' => $kind === 'start'
                        ? __(':project starts', ['project' => $project->name])
                        : __(':project target date', ['project' => $project->name]),
                    'reference' => (string) $project->key,
                    'color' => $color,
                    'projectName' => (string) $project->name,
                    'projectSlug' => (string) $project->slug,
                    'statusName' => $project->health?->label(),
                    'statusColor' => $project->health?->color(),
                    'progress' => (int) $project->progress,
                    'completed' => false,
                    'overdue' => false,
                    'draggable' => false,
                ];
            }
        }

        return $events;
    }

    /* ------------------------------------------------------------------ *
     * Filters
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        if ($this->scopedProject() instanceof Project) {
            return [];
        }

        $options = ['' => __('All projects')];

        $projects = Project::query()
            ->forWorkspace($this->calendarWorkspace())
            ->visibleTo($this->actorOrFail())
            ->active()
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'workspace_id', 'name']);

        foreach ($projects as $project) {
            $options[(string) $project->getKey()] = (string) $project->name;
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function assigneeOptions(): array
    {
        $options = ['' => __('Anyone')];
        $project = $this->scopedProject();

        $members = $project instanceof Project
            ? $project->members()->orderBy('users.name')->limit(200)->get(['users.id', 'users.name'])
            : $this->calendarWorkspace()->members()->orderBy('users.name')->limit(200)->get(['users.id', 'users.name']);

        foreach ($members as $member) {
            $options[(string) $member->getKey()] = (string) $member->name;
        }

        return $options;
    }

    private function filterId(string $value): ?int
    {
        return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }

    /* ------------------------------------------------------------------ *
     * Rescheduling
     * ------------------------------------------------------------------ */

    /**
     * Drop a task on another day.
     *
     * Called by the drop target and by the keyboard controls alike, so the authorization
     * and the invariant handling live in one place rather than once per interaction.
     */
    public function moveTask(mixed $taskId, string $date): void
    {
        $day = $this->parseDate($date);
        $task = $this->findTask($taskId);

        if ($task === null || $day === null) {
            return;
        }

        $this->authorize('update', $task);

        if ($task->due_date?->format('Y-m-d') === $day->toDateString()) {
            return;
        }

        try {
            app(UpdateTask::class)($task, TaskChanges::make()->dueDate($day), $this->actorOrFail());
        } catch (DomainException $exception) {
            // A due date before the start date is a rule, not a fault: the person gets the
            // sentence the action wrote, and the card stays where it was.
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        $this->resetCalendarCache();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __(':task moved to :date', [
                'task' => $task->key,
                'date' => $day->translatedFormat('j M Y'),
            ]),
        );
    }

    /**
     * The keyboard alternative to dragging: move a task on by whole days.
     */
    public function shiftTask(mixed $taskId, int $days): void
    {
        $task = $this->findTask($taskId);

        if ($task === null || $task->due_date === null || $days === 0) {
            return;
        }

        $current = $this->parseDate($task->due_date->format('Y-m-d'));

        if ($current === null) {
            return;
        }

        $this->moveTask($taskId, $current->addDays($days)->toDateString());
    }

    public function openTask(mixed $taskId): void
    {
        $task = $this->findTask($taskId);

        if ($task === null) {
            return;
        }

        $this->authorize('view', $task);

        $this->openTaskId = (int) $task->getKey();
        $this->rescheduleTo = $task->due_date?->format('Y-m-d') ?? '';
        $this->drawerOpen = true;

        unset($this->drawerTask);
    }

    public function closeTask(): void
    {
        $this->openTaskId = null;
        $this->rescheduleTo = '';
        $this->drawerOpen = false;

        unset($this->drawerTask);
    }

    /**
     * The drawer dismissing itself — Escape, or a click on the scrim.
     */
    public function updatedDrawerOpen(bool $open): void
    {
        if (! $open) {
            $this->closeTask();
        }
    }

    public function reschedule(): void
    {
        if ($this->openTaskId === null || $this->rescheduleTo === '') {
            return;
        }

        $this->moveTask($this->openTaskId, $this->rescheduleTo);
    }

    /**
     * The task behind the drawer, loaded with everything the drawer prints.
     */
    #[Computed]
    public function drawerTask(): ?Task
    {
        if ($this->openTaskId === null) {
            return null;
        }

        $task = Task::query()
            ->forWorkspace($this->calendarWorkspace())
            ->whereKey($this->openTaskId)
            ->with([
                'project:id,workspace_id,name,key,slug,color',
                'status:id,name,color,category,is_completed',
                'assignee:id,name,avatar_path',
                'milestone:id,name',
            ])
            ->first();

        if ($task === null) {
            return null;
        }

        $user = $this->actor();

        return $user !== null && $user->can('view', $task) ? $task : null;
    }

    public function drawerExcerpt(?Task $task): string
    {
        return Str::limit(trim(strip_tags((string) ($task?->description ?? ''))), 280);
    }

    private function findTask(mixed $taskId): ?Task
    {
        $id = is_numeric($taskId) ? (int) $taskId : 0;

        if ($id < 1) {
            return null;
        }

        $project = $this->scopedProject();

        return Task::query()
            ->forWorkspace($this->calendarWorkspace())
            ->when(
                $project instanceof Project,
                fn (Builder $query): Builder => $query->where('tasks.project_id', $project?->getKey()),
            )
            ->whereKey($id)
            ->with(['project:id,workspace_id,name,key,slug,color'])
            ->first();
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }

    private function actorOrFail(): User
    {
        $user = $this->actor();

        abort_if($user === null, 403);

        return $user;
    }
}
