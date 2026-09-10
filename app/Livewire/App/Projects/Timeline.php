<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects;

use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\UpdateTask;
use App\Http\Middleware\SetLocale;
use App\Models\Locale;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChartPalette;
use App\Support\Formats;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The project timeline: a Gantt built from CSS grid and absolutely positioned bars.
 *
 * ## Why there is no chart library here
 *
 * Everything this screen does — position a bar, fill part of it, draw an elbow between two
 * of them — is arithmetic over a day axis. A library would bring its own DOM, its own
 * theme, its own accessibility story and a megabyte of JavaScript to an installation that
 * ships without Node. The bars are `<div>`s the browser lays out; only the dependency
 * arrows are SVG, because a line between two arbitrary points is the one thing CSS cannot
 * draw honestly.
 *
 * ## The axis
 *
 * All axis arithmetic happens in UTC on bare `Y-m-d` values. The columns are date-only, the
 * axis is a row of days, and mixing a timezone into either is how a bar ends up a day out
 * across a DST boundary. The workspace's timezone is consulted once, to decide which day
 * "today" is for the marker.
 *
 * The span is derived from the data — one aggregate query over the whole project, not the
 * visible page — so paging through rows never makes the axis jump underneath them. It is
 * then clamped per zoom level: a two-year project at day zoom is 26 000 pixels of DOM that
 * nobody scrolls through, so the view says it has been cut rather than building it.
 *
 * ## 200 rows
 *
 * Milestones are the project's skeleton and are always drawn — there are rarely more than a
 * dozen. Tasks are paginated, because a Gantt that renders two hundred rows and their
 * dependency overlay is a screen that takes a second to paint and a second to scroll.
 *
 * ## Direction
 *
 * Time runs in reading order. In an Arabic page the earliest day is on the **right**, later
 * work extends to the left, and the row headers sit against the right edge — because a plan
 * read backwards is not a plan, and September to the left of October says the wrong thing
 * about the project whatever language the labels are in.
 *
 * Every offset this class produces — a band, a tick, a bar, the today marker — is therefore
 * an offset from the *inline start* of the plot rather than from its left. The view spends
 * them on `inset-inline-start`, so the browser resolves the side, which is why none of the
 * arithmetic here has a direction in it. The one exception is {@see self::dependencies()}:
 * an SVG x is measured from the left of the viewBox whatever the page does, so those paths
 * are reflected explicitly.
 */
#[Layout('layouts.app')]
final class Timeline extends Component
{
    use WithPagination;

    /**
     * Pixels per day, and the widest span worth building, per zoom level.
     *
     * @var array<string, array{dayWidth: float, maxDays: int, tick: string, tier: string}>
     */
    private const ZOOM = [
        'day' => ['dayWidth' => 34.0, 'maxDays' => 120, 'tick' => 'day', 'tier' => 'month'],
        'week' => ['dayWidth' => 15.0, 'maxDays' => 420, 'tick' => 'week', 'tier' => 'month'],
        'month' => ['dayWidth' => 5.0, 'maxDays' => 1100, 'tick' => 'month', 'tier' => 'year'],
        'quarter' => ['dayWidth' => 1.8, 'maxDays' => 2200, 'tick' => 'quarter', 'tier' => 'year'],
    ];

    private const ROW_HEIGHT = 30;

    private const MAX_MILESTONES = 60;

    public Workspace $workspace;

    public Project $project;

    #[Url(as: 'zoom', except: 'week')]
    public string $zoom = 'week';

    #[Url(as: 'group', except: 'none')]
    public string $groupBy = 'none';

    #[Url(as: 'done', except: false)]
    public bool $includeCompleted = false;

    /** The task open in the side panel, if any. */
    public ?int $openTaskId = null;

    public bool $panelOpen = false;

    public string $rescheduleStart = '';

    public string $rescheduleDue = '';

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);

        $this->workspace = $workspace;
        $this->project = $project;
    }

    /* ------------------------------------------------------------------ *
     * Controls
     * ------------------------------------------------------------------ */

    public function setZoom(string $zoom): void
    {
        if (! array_key_exists($zoom, self::ZOOM)) {
            return;
        }

        $this->zoom = $zoom;
        unset($this->timeline);
    }

    public function updatedGroupBy(string $groupBy): void
    {
        if (! in_array($groupBy, ['none', 'milestone', 'assignee'], true)) {
            $this->groupBy = 'none';
        }

        $this->resetPage();
        unset($this->timeline);
    }

    public function updatedIncludeCompleted(): void
    {
        $this->resetPage();
        unset($this->timeline);
    }

    /* ------------------------------------------------------------------ *
     * The chart
     * ------------------------------------------------------------------ */

    public function timezone(): string
    {
        $timezone = (string) ($this->workspace->timezone ?? '');

        return $timezone === '' ? (string) config('app.timezone', 'UTC') : $timezone;
    }

    /**
     * Which way this page reads.
     *
     * {@see SetLocale} shares the resolved direction with every view, and is registered as
     * Livewire persistent middleware, so a `POST /livewire/update` lands on the same answer
     * the full page render used — no second question to the `locales` table for a value
     * that has already been decided. The fallback is for a render with no middleware in
     * front of it, which is how a component under test is usually exercised.
     */
    private function isRtl(): bool
    {
        $shared = View::shared('textDirection');

        return is_string($shared)
            ? $shared === Locale::RTL
            : Locale::directionFor(App::getLocale()) === Locale::RTL;
    }

    private static function axisDate(string $date): CarbonImmutable
    {
        // UTC, always. The axis is a row of days, not a row of instants, and a timezone
        // with a DST switch in it makes `diffInDays` disagree with the calendar.
        return CarbonImmutable::createFromFormat('Y-m-d', $date, 'UTC')->startOfDay();
    }

    /**
     * The span the chart covers, before clamping.
     *
     * One aggregate over the project's tasks and milestones plus the project's own dates.
     * Today is always inside it: a timeline that does not show where you are standing is a
     * picture rather than a plan.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function dataSpan(): array
    {
        $today = CarbonImmutable::now($this->timezone())->format('Y-m-d');

        $tasks = Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->toBase()
            ->selectRaw('min(coalesce(tasks.start_date, tasks.due_date)) as first_day')
            ->selectRaw('max(coalesce(tasks.due_date, tasks.start_date)) as last_day')
            ->first();

        $milestones = Milestone::query()
            ->forWorkspace($this->workspace)
            ->where('milestones.project_id', $this->project->getKey())
            ->toBase()
            ->selectRaw('min(coalesce(milestones.start_date, milestones.due_date)) as first_day')
            ->selectRaw('max(coalesce(milestones.due_date, milestones.start_date)) as last_day')
            ->first();

        $days = array_values(array_filter([
            self::dayOf($tasks?->first_day),
            self::dayOf($tasks?->last_day),
            self::dayOf($milestones?->first_day),
            self::dayOf($milestones?->last_day),
            $this->project->start_date?->format('Y-m-d'),
            $this->project->target_date?->format('Y-m-d'),
            $today,
        ]));

        $first = self::axisDate(min($days));
        $last = self::axisDate(max($days));

        // A little air either side, so a bar never starts flush against the frame.
        return [$first->subDays(3), $last->addDays(3)];
    }

    /**
     * MySQL returns a `date` column bare; SQLite returns whatever the cast wrote. Both are
     * reduced to the ten characters that are actually the day.
     */
    private static function dayOf(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, 10) : null;
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>, width: float, dayWidth: float, rowHeight: int,
     *     tiers: array{top: list<array<string, mixed>>, bottom: list<array<string, mixed>>},
     *     todayLeft: float|null, dependencies: list<array<string, mixed>>,
     *     start: string, end: string, clamped: bool, undated: int, totalTasks: int,
     *     rtl: bool
     * }
     */
    #[Computed]
    public function timeline(): array
    {
        $zoom = self::ZOOM[$this->zoom] ?? self::ZOOM['week'];
        $dayWidth = $zoom['dayWidth'];
        $rtl = $this->isRtl();

        [$first, $last] = $this->dataSpan();

        $spanDays = (int) round($first->diffInDays($last)) + 1;
        $clamped = $spanDays > $zoom['maxDays'];
        $days = min($spanDays, $zoom['maxDays']);

        $todayString = CarbonImmutable::now($this->timezone())->format('Y-m-d');

        if ($clamped) {
            // A window has to be cut somewhere, and the only defensible place to cut it is
            // around the present: a chart that clipped a five-year project to its first
            // four months would open on work finished long ago and hide the week the
            // reader actually came for. Today sits a little left of centre, so the near
            // future — the part a plan is for — gets the larger share.
            $todayOffset = (int) round($first->diffInDays(self::axisDate($todayString)));
            $offset = max(0, min($spanDays - $days, (int) round($todayOffset - $days * 0.4)));
            $first = $first->addDays($offset);
        }

        $end = $first->addDays($days - 1);

        $width = round($days * $dayWidth, 2);

        $rows = $this->rows($first, $end, $dayWidth, $days);

        $todayOffset = (int) round($first->diffInDays(self::axisDate($todayString)));
        $todayLeft = $todayOffset >= 0 && $todayOffset < $days
            ? round(($todayOffset + 0.5) * $dayWidth, 2)
            : null;

        return [
            'rows' => $rows['rows'],
            'width' => $width,
            'dayWidth' => $dayWidth,
            'rowHeight' => self::ROW_HEIGHT,
            'tiers' => $this->tiers($first, $end, $dayWidth, $zoom['tick'], $zoom['tier']),
            'todayLeft' => $todayLeft,
            'dependencies' => $this->dependencies($rows['positions'], $width, $rtl),
            'start' => $first->toDateString(),
            'end' => $end->toDateString(),
            'clamped' => $clamped,
            'undated' => $rows['undated'],
            'totalTasks' => $rows['totalTasks'],
            'rtl' => $rtl,
        ];
    }

    /**
     * The rows, in draw order, plus where each task's bar ended up.
     *
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     positions: array<int, array{row: int, left: float, right: float}>,
     *     undated: int, totalTasks: int
     * }
     */
    private function rows(CarbonImmutable $first, CarbonImmutable $end, float $dayWidth, int $days): array
    {
        $rows = [];
        $positions = [];

        $milestones = Milestone::query()
            ->forWorkspace($this->workspace)
            ->where('milestones.project_id', $this->project->getKey())
            ->ordered()
            ->limit(self::MAX_MILESTONES)
            ->get();

        foreach ($milestones as $milestone) {
            $bar = $this->bar(
                $milestone->start_date?->format('Y-m-d'),
                $milestone->due_date?->format('Y-m-d'),
                $first,
                $end,
                $dayWidth,
                $days,
            );

            $rows[] = [
                'kind' => 'milestone',
                'id' => (int) $milestone->getKey(),
                'label' => (string) $milestone->name,
                'sublabel' => $milestone->due_date?->format('Y-m-d'),
                'badge' => $milestone->status?->label(),
                'badgeColor' => $milestone->status?->color() ?? 'gray',
                'progress' => (int) $milestone->progress,
                'color' => ChartPalette::color('purple'),
                'bar' => $bar,
                'href' => null,
            ];
        }

        $paginator = $this->taskQuery()->paginate(
            (int) config('planvio.pagination.list', 50),
            ['*'],
            'page',
        );

        // Counted on its own query rather than by cloning the paginated one: that builder
        // carries joins and an ORDER BY over them, and an aggregate over that is a
        // different question on every engine.
        $undated = Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->whereNull('tasks.start_date')
            ->whereNull('tasks.due_date')
            ->when(! $this->includeCompleted, fn (Builder $query): Builder => $query->whereNull('tasks.completed_at'))
            ->count();

        $group = null;

        foreach ($paginator->items() as $task) {
            $header = $this->groupHeaderFor($task);

            if ($header !== null && $header !== $group) {
                $group = $header;

                $rows[] = [
                    'kind' => 'group',
                    'id' => 0,
                    'label' => $header,
                    'sublabel' => null,
                    'badge' => null,
                    'badgeColor' => 'gray',
                    'progress' => 0,
                    'color' => ChartPalette::color('gray'),
                    'bar' => null,
                    'href' => null,
                ];
            }

            $bar = $this->bar(
                $task->start_date?->format('Y-m-d'),
                $task->due_date?->format('Y-m-d'),
                $first,
                $end,
                $dayWidth,
                $days,
            );

            if ($bar !== null) {
                $positions[(int) $task->getKey()] = [
                    'row' => count($rows),
                    'left' => $bar['left'],
                    'right' => $bar['left'] + $bar['width'],
                ];
            }

            $rows[] = [
                'kind' => 'task',
                'id' => (int) $task->getKey(),
                'label' => (string) $task->title,
                'reference' => (string) $task->key,
                'sublabel' => $task->assignee?->name,
                'assignee' => $task->assignee,
                'badge' => $task->status?->name,
                'badgeColor' => $task->status?->color ?? 'gray',
                'progress' => (int) $task->progress,
                'completed' => $task->is_completed,
                'color' => $task->is_completed
                    ? ChartPalette::color('green')
                    : ($task->priority?->value === 'urgent' || $task->priority?->value === 'high'
                        ? ChartPalette::color($task->priority->color())
                        : ChartPalette::color('brand')),
                'bar' => $bar,
                'href' => route('app.tasks.show', [$this->workspace, $task]),
            ];
        }

        $this->paginator = $paginator;

        return [
            'rows' => $rows,
            'positions' => $positions,
            'undated' => $undated,
            'totalTasks' => $paginator->total(),
        ];
    }

    /** @var LengthAwarePaginator<int, Task>|null */
    protected mixed $paginator = null;

    /**
     * @return Builder<Task>
     */
    private function taskQuery(): Builder
    {
        $query = Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->with([
                'status:id,name,color,category,is_completed',
                'assignee:id,name,avatar_path',
                'milestone:id,name,position',
                'project:id,workspace_id,name,key,slug',
            ]);

        if (! $this->includeCompleted) {
            $query->whereNull('tasks.completed_at');
        }

        return match ($this->groupBy) {
            'milestone' => $query
                ->leftJoin('milestones', 'milestones.id', '=', 'tasks.milestone_id')
                ->select('tasks.*')
                ->orderByRaw('case when tasks.milestone_id is null then 1 else 0 end')
                ->orderBy('milestones.position')
                ->orderBy('milestones.id')
                ->orderByRaw('case when tasks.start_date is null and tasks.due_date is null then 1 else 0 end')
                ->orderByRaw('coalesce(tasks.start_date, tasks.due_date)')
                ->orderBy('tasks.id'),
            'assignee' => $query
                ->leftJoin('users', 'users.id', '=', 'tasks.assignee_id')
                ->select('tasks.*')
                ->orderByRaw('case when tasks.assignee_id is null then 1 else 0 end')
                ->orderBy('users.name')
                ->orderByRaw('coalesce(tasks.start_date, tasks.due_date)')
                ->orderBy('tasks.id'),
            default => $query
                ->orderByRaw('case when tasks.start_date is null and tasks.due_date is null then 1 else 0 end')
                ->orderByRaw('coalesce(tasks.start_date, tasks.due_date)')
                ->orderBy('tasks.id'),
        };
    }

    private function groupHeaderFor(Task $task): ?string
    {
        return match ($this->groupBy) {
            'milestone' => $task->milestone?->name ?? __('No milestone'),
            'assignee' => $task->assignee?->name ?? __('Unassigned'),
            default => null,
        };
    }

    /**
     * Where a bar sits, clipped to the visible span.
     *
     * A task with only one of the two dates is drawn as a one-day marker on that date
     * rather than being dropped: "due the 14th, no start recorded" is a real and common
     * state, and a plan that hides it is a plan that is missing work.
     *
     * @return array{left: float, width: float, clippedStart: bool, clippedEnd: bool, from: string, to: string}|null
     */
    private function bar(
        ?string $start,
        ?string $due,
        CarbonImmutable $first,
        CarbonImmutable $end,
        float $dayWidth,
        int $days,
    ): ?array {
        $from = $start ?? $due;
        $to = $due ?? $start;

        if ($from === null || $to === null) {
            return null;
        }

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $startIndex = (int) round($first->diffInDays(self::axisDate($from)));
        $endIndex = (int) round($first->diffInDays(self::axisDate($to)));

        if ($endIndex < 0 || $startIndex >= $days) {
            return null;
        }

        $clippedStart = $startIndex < 0;
        $clippedEnd = $endIndex > $days - 1;

        $visibleStart = max(0, $startIndex);
        $visibleEnd = min($days - 1, $endIndex);

        return [
            'left' => round($visibleStart * $dayWidth, 2),
            'width' => round(max($dayWidth * 0.75, ($visibleEnd - $visibleStart + 1) * $dayWidth), 2),
            'clippedStart' => $clippedStart,
            'clippedEnd' => $clippedEnd,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * The two header tiers.
     *
     * @return array{top: list<array<string, mixed>>, bottom: list<array<string, mixed>>}
     */
    private function tiers(CarbonImmutable $first, CarbonImmutable $end, float $dayWidth, string $tick, string $tier): array
    {
        $bottom = [];
        $cursor = $first;
        $weekStart = Formats::weekStartsOn($this->workspace);
        $today = CarbonImmutable::now($this->timezone())->format('Y-m-d');

        while ($cursor->lessThanOrEqualTo($end)) {
            $next = match ($tick) {
                'day' => $cursor->addDay(),
                'week' => $cursor->addDays(7 - (($cursor->dayOfWeek - $weekStart + 7) % 7)),
                'month' => $cursor->addMonthNoOverflow()->startOfMonth(),
                default => $cursor->addMonthsNoOverflow(3)->firstOfQuarter(),
            };

            if ($next->greaterThan($end->addDay())) {
                $next = $end->addDay();
            }

            $offset = (int) round($first->diffInDays($cursor));
            $length = max(1, (int) round($cursor->diffInDays($next)));

            $bottom[] = [
                'left' => round($offset * $dayWidth, 2),
                'width' => round($length * $dayWidth, 2),
                'label' => match ($tick) {
                    'day' => $cursor->format('j'),
                    'week' => $cursor->translatedFormat('j M'),
                    'month' => $cursor->translatedFormat('M'),
                    default => 'Q'.$cursor->quarter,
                },
                'weekend' => $tick === 'day' && in_array((int) $cursor->format('w'), [0, 6], true),
                'today' => $tick === 'day' && $cursor->toDateString() === $today,
            ];

            $cursor = $next;
        }

        $top = [];
        $cursor = $first;

        while ($cursor->lessThanOrEqualTo($end)) {
            $next = $tier === 'month'
                ? $cursor->addMonthNoOverflow()->startOfMonth()
                : $cursor->addYear()->startOfYear();

            if ($next->greaterThan($end->addDay())) {
                $next = $end->addDay();
            }

            $offset = (int) round($first->diffInDays($cursor));
            $length = max(1, (int) round($cursor->diffInDays($next)));

            $top[] = [
                'left' => round($offset * $dayWidth, 2),
                'width' => round($length * $dayWidth, 2),
                'label' => $tier === 'month'
                    ? $cursor->translatedFormat('F Y')
                    : $cursor->format('Y'),
                'narrow' => $length * $dayWidth < 56,
            ];

            $cursor = $next;
        }

        return ['top' => $top, 'bottom' => $bottom];
    }

    /**
     * The dependency arrows, as SVG paths.
     *
     * Only edges whose *both* ends are on this page get drawn. An arrow that leaves the
     * screen and points at nothing is worse than no arrow: it says a relationship exists
     * and then refuses to say with what. The row footer says how many were left out.
     *
     * Everything in `$positions` is measured from the *inline start* of the plot, because
     * that is how the bars themselves are placed — `inset-inline-start`, which is the left
     * edge in English and the right edge in Arabic. This overlay is an SVG, and an SVG x is
     * always measured from the left of the viewBox, so a right-to-left page reflects each x
     * through the width of the chart on the way into the path. The arrowheads follow for
     * free: `orient="auto"` takes its angle from the path, and a reflected path arrives at
     * its target from the other side.
     *
     * @param array<int, array{row: int, left: float, right: float}> $positions
     * @return list<array{path: string, dashed: bool, blocking: bool, label: string}>
     */
    private function dependencies(array $positions, float $width, bool $rtl): array
    {
        $ids = array_keys($positions);

        if (count($ids) < 2) {
            return [];
        }

        $edges = TaskDependency::query()
            ->forWorkspace($this->workspace)
            ->whereIn('task_dependencies.task_id', $ids)
            ->whereIn('task_dependencies.depends_on_task_id', $ids)
            ->limit(400)
            ->get(['id', 'workspace_id', 'task_id', 'depends_on_task_id', 'type']);

        $height = self::ROW_HEIGHT;
        $paths = [];
        $fx = static fn (float $x): float => round($rtl ? $width - $x : $x, 2);

        foreach ($edges as $edge) {
            $from = $positions[(int) $edge->depends_on_task_id] ?? null;
            $to = $positions[(int) $edge->task_id] ?? null;

            if ($from === null || $to === null) {
                continue;
            }

            $y1 = $from['row'] * $height + $height / 2;
            $y2 = $to['row'] * $height + $height / 2;
            $x1 = $from['right'];
            $x2 = max(0.0, $to['left'] - 6);

            // The straightforward case: the dependent starts after the thing it waits for
            // finishes, so the arrow steps out, changes row, and comes in.
            if ($x2 >= $x1 + 14) {
                $mid = $x1 + 8;
                $path = sprintf('M %s %s H %s V %s H %s', $fx($x1), $y1, $fx($mid), $y2, $fx($x2));
            } else {
                // The overlapping case — which is exactly the schedule problem worth
                // seeing — routed around the outside rather than drawn through the bars.
                $lane = $y2 > $y1 ? $y1 + $height / 2 : $y1 - $height / 2;
                $path = sprintf(
                    'M %s %s H %s V %s H %s V %s H %s',
                    $fx($x1), $y1, $fx($x1 + 8), $lane, $fx(max(0.0, $x2 - 10)), $y2, $fx($x2),
                );
            }

            $paths[] = [
                'path' => $path,
                'dashed' => $edge->type?->isBlocking() !== true,
                'blocking' => $edge->type?->isBlocking() === true,
                'label' => $edge->type?->label() ?? '',
            ];
        }

        return $paths;
    }

    /* ------------------------------------------------------------------ *
     * Editing
     * ------------------------------------------------------------------ */

    public function openTask(mixed $taskId): void
    {
        $task = $this->findTask($taskId);

        if ($task === null) {
            return;
        }

        $this->authorize('view', $task);

        $this->openTaskId = (int) $task->getKey();
        $this->rescheduleStart = $task->start_date?->format('Y-m-d') ?? '';
        $this->rescheduleDue = $task->due_date?->format('Y-m-d') ?? '';
        $this->panelOpen = true;

        unset($this->panelTask);
    }

    public function closeTask(): void
    {
        $this->openTaskId = null;
        $this->rescheduleStart = '';
        $this->rescheduleDue = '';
        $this->panelOpen = false;

        unset($this->panelTask);
    }

    public function updatedPanelOpen(bool $open): void
    {
        if (! $open) {
            $this->closeTask();
        }
    }

    #[Computed]
    public function panelTask(): ?Task
    {
        if ($this->openTaskId === null) {
            return null;
        }

        $task = $this->findTask($this->openTaskId);

        if ($task === null) {
            return null;
        }

        $user = Auth::user();

        return $user instanceof User && $user->can('view', $task) ? $task : null;
    }

    /**
     * Reschedule a bar. Both ends at once, because moving one without the other is how a
     * plan quietly acquires a task that finishes before it starts.
     */
    public function reschedule(): void
    {
        $task = $this->findTask($this->openTaskId);

        if ($task === null) {
            return;
        }

        $this->authorize('update', $task);

        $changes = TaskChanges::make()
            ->startDate($this->rescheduleStart === '' ? null : self::axisDate($this->rescheduleStart))
            ->dueDate($this->rescheduleDue === '' ? null : self::axisDate($this->rescheduleDue));

        try {
            app(UpdateTask::class)($task, $changes, $this->actor());
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        unset($this->timeline, $this->panelTask);

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __(':task rescheduled', ['task' => $task->key]),
        );
    }

    /**
     * Slide a bar without changing its length — the keyboard equivalent of dragging it.
     */
    public function shiftTask(mixed $taskId, int $days): void
    {
        $task = $this->findTask($taskId);

        if ($task === null || $days === 0) {
            return;
        }

        $this->authorize('update', $task);

        $changes = TaskChanges::make();

        if ($task->start_date !== null) {
            $changes = $changes->startDate(self::axisDate($task->start_date->format('Y-m-d'))->addDays($days));
        }

        if ($task->due_date !== null) {
            $changes = $changes->dueDate(self::axisDate($task->due_date->format('Y-m-d'))->addDays($days));
        }

        if ($changes->isEmpty()) {
            return;
        }

        try {
            app(UpdateTask::class)($task, $changes, $this->actor());
        } catch (DomainException $exception) {
            $this->dispatch('planvio-notify', type: 'error', message: $exception->getMessage());

            return;
        }

        unset($this->timeline, $this->panelTask);

        if ($this->openTaskId === (int) $task->getKey()) {
            $fresh = $task->fresh();
            $this->rescheduleStart = $fresh?->start_date?->format('Y-m-d') ?? '';
            $this->rescheduleDue = $fresh?->due_date?->format('Y-m-d') ?? '';
        }

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __(':task rescheduled', ['task' => $task->key]),
        );
    }

    private function findTask(mixed $taskId): ?Task
    {
        $id = is_numeric($taskId) ? (int) $taskId : 0;

        if ($id < 1) {
            return null;
        }

        return Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->whereKey($id)
            ->with([
                'project:id,workspace_id,name,key,slug',
                'status:id,name,color,category,is_completed',
                'assignee:id,name,avatar_path',
                'milestone:id,name',
            ])
            ->first();
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * @return LengthAwarePaginator<int, Task>|null
     */
    public function rowPaginator(): mixed
    {
        // Reading the computed property is what builds it; the paginator is a by-product
        // of the same pass, so the view never runs the task query twice.
        $this->timeline;

        return $this->paginator;
    }

    public function render(): ViewContract
    {
        return view('livewire.app.projects.timeline')
            ->title($this->project->name.' · '.__('Timeline'));
    }
}
