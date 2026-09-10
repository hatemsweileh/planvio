<?php

declare(strict_types=1);

namespace App\Livewire\App\Reports;

use App\Actions\Tasks\AssignTask;
use App\Enums\AiRunStatus;
use App\Enums\Permission;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Milestone;
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
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One project's status, as a document.
 *
 * Rendered in `layouts.print` rather than the product shell, because this page's job is to
 * be read by somebody who does not use Planvio: printed, saved as a PDF, pasted into a
 * board pack. Everything that is chrome on screen — the sidebar, the palette, the buttons —
 * is either absent or marked `print:hidden`.
 *
 * ## Two kinds of statement, kept apart
 *
 * The report separates what Planvio *stores* from what Planvio *infers*, and never lets the
 * second borrow the authority of the first. Counts, dates, hours and amounts are recorded
 * figures. The health verdict is a calculation, and it is printed with the signals it rests
 * on. Where AI is enabled, an executive summary may appear — in its own bordered block,
 * labelled as AI-written, dated, and placed after the figures rather than above them, so
 * nobody reads a generated paragraph as a measurement.
 *
 * ## Permissions
 *
 * Money and everybody's hours are separate permissions from the report itself. A section
 * the reader may not see is omitted and named in a short note, because a budget section
 * showing zero is worse than a budget section that is honestly missing.
 */
#[Layout('layouts.print')]
final class StatusReport extends Component
{
    private const MAX_LIST = 25;

    public Workspace $workspace;

    public Project $project;

    #[Url(as: 'range', except: 'last_30')]
    public string $preset = 'last_30';

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('reports.view', $project);

        $this->workspace = $workspace;
        $this->project = $project;
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
            'all' => __('Whole project'),
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
            'all' => DateRange::everything(),
            default => DateRange::lastDays(30, $timezone),
        };
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /* ------------------------------------------------------------------ *
     * Recorded figures
     * ------------------------------------------------------------------ */

    #[Computed]
    public function progress(): ProjectProgress
    {
        return app(ProjectProgressCalculator::class)->forProject($this->project);
    }

    /**
     * @return array{overdue: int, dueSoon: int, completedInRange: int, unassignedOpen: int}
     */
    #[Computed]
    public function counts(): array
    {
        $today = $this->today()->toDateString();
        $soon = $this->today()->addDays(14)->toDateString();
        $range = $this->range;

        $row = Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
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
            ->selectRaw(
                'sum(case when tasks.completed_at is not null and tasks.completed_at >= ?'
                .' and tasks.completed_at < ? then 1 else 0 end) as completed_in_range',
                [$range->fromDate().' 00:00:00', $range->toExclusiveDate().' 00:00:00'],
            )
            ->selectRaw('sum(case when tasks.completed_at is null and tasks.assignee_id is null then 1 else 0 end) as unassigned_open')
            ->toBase()
            ->first();

        return [
            'overdue' => (int) ($row->overdue_tasks ?? 0),
            'dueSoon' => (int) ($row->due_soon_tasks ?? 0),
            'completedInRange' => (int) ($row->completed_in_range ?? 0),
            'unassignedOpen' => (int) ($row->unassigned_open ?? 0),
        ];
    }

    /**
     * Work closed inside the reporting window — the "what got done" half of a status report.
     *
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function completed(): EloquentCollection
    {
        $range = $this->range;

        return Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->whereNotNull('tasks.completed_at')
            ->where('tasks.completed_at', '>=', $range->fromDate().' 00:00:00')
            ->where('tasks.completed_at', '<', $range->toExclusiveDate().' 00:00:00')
            ->with(['assignee:id,name,avatar_path', 'project:id,workspace_id,name,key,slug', 'status:id,name,color,category,is_completed'])
            ->orderByDesc('tasks.completed_at')
            ->limit(self::MAX_LIST)
            ->get();
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function upcoming(): EloquentCollection
    {
        $today = $this->today()->toDateString();
        $horizon = $this->today()->addDays(14)->toDateString();

        return Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->whereNull('tasks.completed_at')
            ->whereNotNull('tasks.due_date')
            ->where('tasks.due_date', '>=', $today)
            ->where('tasks.due_date', '<', $horizon)
            ->with(['assignee:id,name,avatar_path', 'project:id,workspace_id,name,key,slug', 'status:id,name,color,category,is_completed'])
            ->orderBy('tasks.due_date')
            ->limit(self::MAX_LIST)
            ->get();
    }

    /**
     * @return EloquentCollection<int, Task>
     */
    #[Computed]
    public function overdue(): EloquentCollection
    {
        return Task::query()
            ->forWorkspace($this->workspace)
            ->where('tasks.project_id', $this->project->getKey())
            ->overdue($this->today())
            ->with(['assignee:id,name,avatar_path', 'project:id,workspace_id,name,key,slug', 'status:id,name,color,category,is_completed'])
            ->orderBy('tasks.due_date')
            ->limit(self::MAX_LIST)
            ->get();
    }

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function milestones(): EloquentCollection
    {
        return Milestone::query()
            ->forWorkspace($this->workspace)
            ->where('milestones.project_id', $this->project->getKey())
            ->ordered()
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function health(): ProjectHealthAssessment
    {
        return app(ProjectHealthCalculator::class)->calculate($this->project, $this->today());
    }

    /**
     * @return list<WorkloadRow>
     */
    #[Computed]
    public function team(): array
    {
        return app(WorkloadService::class)->forProject($this->project, null, $this->today());
    }

    /**
     * Names for the people holding work who are not on the project's member list.
     *
     * {@see WorkloadService} builds its roster from `project_members`, so anybody assigned
     * without being added to the project comes back nameless — and a report is exactly where
     * that happens, because assignment only requires workspace membership
     * ({@see AssignTask}). Printing "Former member" beside four open
     * tasks belonging to somebody who is sitting in the room is worse than an extra query,
     * so the missing names are resolved here, in one lookup, and only a genuinely deleted
     * account is left unnamed.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function offRosterNames(): array
    {
        $ids = [];

        foreach ($this->team as $row) {
            if (! $row->isUnassigned() && $row->userName === null && $row->userId !== null) {
                $ids[] = $row->userId;
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var array<int, string> $names */
        $names = User::query()
            ->whereIn('id', $ids)
            ->pluck('name', 'id')
            ->map(static fn (mixed $name): string => (string) $name)
            ->all();

        return $names;
    }

    public function canSeeBudget(): bool
    {
        return Gate::allows(Permission::BudgetView->value, $this->project);
    }

    public function canSeeAllTime(): bool
    {
        return Gate::allows(Permission::TimeViewAll->value, $this->project);
    }

    #[Computed]
    public function budget(): ?ProjectBudget
    {
        return $this->canSeeBudget()
            ? app(BudgetService::class)->forProject($this->project, $this->range)
            : null;
    }

    /**
     * @return array{total: int, billable: int, byUser: list<TimeReportRow>, own: bool}
     */
    #[Computed]
    public function time(): array
    {
        $service = app(TimeReportService::class);
        $range = $this->range;
        $own = ! $this->canSeeAllTime();
        $user = $own ? $this->actor() : null;

        return [
            'total' => $service->totalMinutes($this->project, $range, $user),
            'billable' => $service->billableMinutes($this->project, $range, $user),
            'byUser' => $own ? [] : $service->byUser($this->project, $range),
            'own' => $own,
        ];
    }

    /**
     * @return list<string>
     */
    public function omittedSections(): array
    {
        $omitted = [];

        if (! $this->canSeeBudget()) {
            $omitted[] = __('Budget');
        }

        if (! $this->canSeeAllTime()) {
            $omitted[] = __('Everyone\'s time');
        }

        return $omitted;
    }

    /* ------------------------------------------------------------------ *
     * The AI paragraph, and why it is fenced off
     * ------------------------------------------------------------------ */

    public function aiAvailable(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User || ! Gate::allows(Permission::AiUse->value, $this->project)) {
            return false;
        }

        return AiSetting::forWorkspace($this->workspace)?->isUsable() === true;
    }

    /**
     * The most recent AI run that produced a summary for this project.
     *
     * Nothing is generated while a page renders. A status report has to load in front of a
     * room, and an outbound model call in a render path is a page that hangs for as long as
     * somebody else's API takes. What is shown here is a run that already happened, with
     * its date and model attached so a reader can judge how stale it is; the button beside
     * it starts a new one through the AI panel, on purpose and in the open.
     */
    #[Computed]
    public function aiSummary(): ?AiRun
    {
        if (! $this->aiAvailable()) {
            return null;
        }

        return AiRun::query()
            ->forWorkspace($this->workspace)
            ->where('ai_runs.project_id', $this->project->getKey())
            ->whereIn('ai_runs.status', [AiRunStatus::Succeeded->value, AiRunStatus::Partial->value])
            ->whereNotNull('ai_runs.summary')
            ->where('ai_runs.summary', '!=', '')
            ->orderByDesc('ai_runs.finished_at')
            ->orderByDesc('ai_runs.id')
            ->first();
    }

    /* ------------------------------------------------------------------ *
     * Formatting
     * ------------------------------------------------------------------ */

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

    private function actor(): User
    {
        $user = Auth::user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    public function render(): View
    {
        // Loaded explicitly on every render rather than once in mount(): the component is
        // rehydrated between requests and a relation loaded in mount() would be gone by the
        // second one, which under `preventLazyLoading` is not a slow page but an exception.
        $this->project->loadMissing([
            'owner:id,name,avatar_path',
            'manager:id,name,avatar_path',
            'status:id,workspace_id,name,color,category',
        ]);

        return view('livewire.app.reports.status-report')
            ->title($this->project->name.' · '.__('Status report'));
    }
}
