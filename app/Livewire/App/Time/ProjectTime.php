<?php

declare(strict_types=1);

namespace App\Livewire\App\Time;

use App\Enums\Permission;
use App\Livewire\App\Time\Concerns\ManagesTime;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DateRange;
use App\Services\TimeReportRow;
use App\Services\TimeReportService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The hours booked against one project.
 *
 * Two audiences, one screen. Somebody with `time.view_all` sees the whole project's time,
 * broken down by person and by day. Somebody without it sees their own — the same layout,
 * the same controls, a narrower question — rather than an empty page or a refusal, because
 * "how much have I put into this" is a fair question for anybody who logs time at all.
 *
 * The breakdowns come from {@see TimeReportService}: one grouped query each, never a loop
 * over people or days.
 */
#[Layout('layouts.app')]
final class ProjectTime extends Component
{
    use ManagesTime;
    use WithPagination;

    public Workspace $workspace;

    public Project $project;

    #[Url(as: 'range', except: 'last_30')]
    public string $preset = 'last_30';

    #[Url(as: 'from', except: '')]
    public string $customFrom = '';

    #[Url(as: 'to', except: '')]
    public string $customTo = '';

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', [TimeEntry::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;
        $this->formDate = $this->today()->toDateString();
        $this->formProject = (string) $project->getKey();
    }

    protected function timeWorkspace(): Workspace
    {
        return $this->workspace;
    }

    protected function timeProject(): ?Project
    {
        return $this->project;
    }

    protected function refreshTimeData(): void
    {
        unset($this->entries, $this->breakdown, $this->range);
    }

    public function updatedPreset(): void
    {
        $this->resetPage();
        $this->refreshTime();
    }

    public function updatedCustomFrom(): void
    {
        $this->preset = 'custom';
        $this->resetPage();
        $this->refreshTime();
    }

    public function updatedCustomTo(): void
    {
        $this->preset = 'custom';
        $this->resetPage();
        $this->refreshTime();
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
            'all' => __('All time'),
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
            'all' => DateRange::everything(),
            'custom' => $this->customRange($today),
            default => DateRange::lastDays(30, $timezone),
        };
    }

    private function customRange(CarbonImmutable $today): DateRange
    {
        $from = $this->parseDate($this->customFrom) ?? $today->subDays(29);
        $to = $this->parseDate($this->customTo) ?? $today;

        return $to->lessThan($from) ? new DateRange($to, $from) : new DateRange($from, $to);
    }

    public function canSeeEveryone(): bool
    {
        return Gate::allows(Permission::TimeViewAll->value, $this->project);
    }

    /**
     * @return array{
     *     byUser: list<TimeReportRow>, byTask: list<TimeReportRow>, byDay: list<TimeReportRow>,
     *     total: int, billable: int, own: bool
     * }
     */
    #[Computed]
    public function breakdown(): array
    {
        $service = app(TimeReportService::class);
        $range = $this->range;
        $own = ! $this->canSeeEveryone();
        $user = $own ? $this->actor() : null;

        return [
            'byUser' => $own ? [] : $service->byUser($this->project, $range),
            'byTask' => $service->byTask($this->project, $range, $user),
            'byDay' => $service->byDay($this->project, $range, $user),
            'total' => $service->totalMinutes($this->project, $range, $user),
            'billable' => $service->billableMinutes($this->project, $range, $user),
            'own' => $own,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, TimeEntry>
     */
    #[Computed]
    public function entries(): LengthAwarePaginator
    {
        $range = $this->range;

        $query = TimeEntry::query()
            ->forWorkspace($this->workspace)
            ->forProject($this->project)
            ->between($range->fromDate(), $range->toDate())
            ->with([
                'project:id,workspace_id,name,key,slug',
                'task:id,workspace_id,project_id,number,title',
                'user:id,name,avatar_path',
            ])
            ->orderByDesc('spent_on')
            ->orderByDesc('id');

        if (! $this->canSeeEveryone()) {
            $query->forUser($this->actor());
        }

        return $query->paginate((int) config('planvio.pagination.list', 50));
    }

    public function canEdit(TimeEntry $entry): bool
    {
        $user = $this->actor();

        return $user instanceof User && $user->can('update', $entry);
    }

    public function render(): View
    {
        return view('livewire.app.time.project-time')
            ->title($this->project->name.' · '.__('Time'));
    }
}
