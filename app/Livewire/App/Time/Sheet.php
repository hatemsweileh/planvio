<?php

declare(strict_types=1);

namespace App\Livewire\App\Time;

use App\Enums\Permission;
use App\Livewire\App\Time\Concerns\ManagesTime;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\Workspace;
use App\Support\Formats;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One person's week.
 *
 * A timesheet is a week, not a list: the question it answers is "have I accounted for the
 * days", and that question needs the empty days visible. So the grid is always seven
 * columns — the workspace's week, starting on the day the workspace starts its week — and a
 * day with nothing on it is a cell reading zero rather than a row that is missing.
 *
 * Everything here is scoped to the signed-in person. Reading somebody else's hours is the
 * project time screen's job, and it asks for `time.view_all` before it does.
 */
#[Layout('layouts.app')]
final class Sheet extends Component
{
    use ManagesTime;

    public Workspace $workspace;

    /** Any day inside the week being shown, `Y-m-d`. Empty means this week. */
    #[Url(as: 'week', except: '')]
    public string $anchor = '';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('view', $workspace);

        // The workspace is named rather than inferred. `TimeEntryPolicy::viewAny()` with no
        // project falls back to whatever tenant happens to be bound, and a component that
        // authorised itself against an ambient value would be asking a different question
        // in a job, in a test and in the browser.
        $this->authorize(Permission::TimeLog->value, $workspace);

        $this->workspace = $workspace;
        $this->formDate = $this->today()->toDateString();
    }

    protected function timeWorkspace(): Workspace
    {
        return $this->workspace;
    }

    protected function timeProject(): ?Project
    {
        return null;
    }

    protected function refreshTimeData(): void
    {
        unset($this->week, $this->entries, $this->totals, $this->entriesByDay);
    }

    /* ------------------------------------------------------------------ *
     * Navigation
     * ------------------------------------------------------------------ */

    public function goToThisWeek(): void
    {
        $this->anchor = '';
        $this->refreshTime();
    }

    public function goPreviousWeek(): void
    {
        $this->anchor = $this->weekStart()->subWeek()->toDateString();
        $this->refreshTime();
    }

    public function goNextWeek(): void
    {
        $this->anchor = $this->weekStart()->addWeek()->toDateString();
        $this->refreshTime();
    }

    public function weekStartsOn(): int
    {
        return Formats::weekStartsOn($this->workspace);
    }

    public function weekStart(): CarbonImmutable
    {
        $anchor = $this->parseDate($this->anchor) ?? $this->today();

        return $anchor->startOfWeek($this->weekStartsOn());
    }

    /**
     * The seven days, with what was logged on each.
     *
     * @return array{
     *     days: list<array{date: string, label: string, weekday: string, isToday: bool, minutes: int, billable: int, entries: int}>,
     *     from: string, to: string, title: string
     * }
     */
    #[Computed]
    public function week(): array
    {
        $start = $this->weekStart();
        $end = $start->addDays(6);
        $today = $this->today()->toDateString();

        $byDay = [];

        foreach ($this->entries as $entry) {
            $day = $entry->spent_on?->format('Y-m-d');

            if ($day === null) {
                continue;
            }

            $byDay[$day]['minutes'] = ($byDay[$day]['minutes'] ?? 0) + (int) $entry->minutes;
            $byDay[$day]['billable'] = ($byDay[$day]['billable'] ?? 0)
                + ($entry->is_billable ? (int) $entry->minutes : 0);
            $byDay[$day]['entries'] = ($byDay[$day]['entries'] ?? 0) + 1;
        }

        $days = [];
        $cursor = $start;

        while ($cursor->lessThanOrEqualTo($end)) {
            $date = $cursor->toDateString();

            $days[] = [
                'date' => $date,
                'label' => $cursor->translatedFormat('j M'),
                'weekday' => $cursor->translatedFormat('D'),
                'isToday' => $date === $today,
                'minutes' => (int) ($byDay[$date]['minutes'] ?? 0),
                'billable' => (int) ($byDay[$date]['billable'] ?? 0),
                'entries' => (int) ($byDay[$date]['entries'] ?? 0),
            ];

            $cursor = $cursor->addDay();
        }

        return [
            'days' => $days,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            'title' => $start->format('Y-m') === $end->format('Y-m')
                ? $start->translatedFormat('j').' – '.$end->translatedFormat('j F Y')
                : $start->translatedFormat('j M').' – '.$end->translatedFormat('j M Y'),
        ];
    }

    /**
     * @return EloquentCollection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): EloquentCollection
    {
        $start = $this->weekStart();

        return TimeEntry::query()
            ->forWorkspace($this->workspace)
            ->forUser($this->actor())
            ->between($start->toDateString(), $start->addDays(6)->toDateString())
            ->with([
                'project:id,workspace_id,name,key,slug,color',
                'task:id,workspace_id,project_id,number,title',
            ])
            ->orderBy('spent_on')
            ->orderByDesc('id')
            ->limit(500)
            ->get();
    }

    /**
     * @return array{minutes: int, billable: int, entries: int, days: int}
     */
    #[Computed]
    public function totals(): array
    {
        $minutes = 0;
        $billable = 0;
        $days = [];

        foreach ($this->entries as $entry) {
            $minutes += (int) $entry->minutes;
            $billable += $entry->is_billable ? (int) $entry->minutes : 0;
            $days[$entry->spent_on?->format('Y-m-d') ?? ''] = true;
        }

        return [
            'minutes' => $minutes,
            'billable' => $billable,
            'entries' => $this->entries->count(),
            'days' => count(array_filter(array_keys($days))),
        ];
    }

    /**
     * The week's entries grouped by day, in the order the grid draws them.
     *
     * @return array<string, list<TimeEntry>>
     */
    #[Computed]
    public function entriesByDay(): array
    {
        $grouped = [];

        foreach ($this->entries as $entry) {
            $day = $entry->spent_on?->format('Y-m-d');

            if ($day !== null) {
                $grouped[$day][] = $entry;
            }
        }

        return $grouped;
    }

    public function render(): View
    {
        return view('livewire.app.time.sheet')->title(__('My time'));
    }
}
