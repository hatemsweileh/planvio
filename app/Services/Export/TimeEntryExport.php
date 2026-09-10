<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Logged time, one row per entry.
 *
 * `time.view_all` is a per-project right, so the reach is decided project by project: for
 * a project where the reader holds it, every entry; everywhere else, only their own. That
 * is the same rule the project time screen and the status report apply, and applying it
 * here rather than refusing the whole export is what makes the file useful to a member who
 * simply wants their own hours for an invoice.
 *
 * A running timer is included with the minutes it has accrued so far, flagged in its own
 * column. Excluding it would make today's total look wrong to the person who is watching
 * the clock tick.
 */
final class TimeEntryExport implements CsvExport
{
    /** @var array{all: list<int>, own: list<int>}|null */
    private ?array $reach = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly User $reader,
        private readonly ExportFilters $filters,
    ) {}

    public function subject(): string
    {
        return 'time-entries';
    }

    /**
     * @return list<string>
     */
    public function headers(): array
    {
        return [
            __('Date'),
            __('Project'),
            __('Project key'),
            __('Task'),
            __('Task title'),
            __('Person'),
            __('Email'),
            __('Minutes'),
            __('Hours'),
            __('Billable'),
            __('Running'),
            __('Description'),
            __('Started at'),
            __('Ended at'),
        ];
    }

    public function total(): int
    {
        return $this->query()->count();
    }

    /**
     * @return Generator<int, list<scalar|null>>
     */
    public function rows(): Generator
    {
        $query = $this->query()
            ->with([
                'project:id,workspace_id,name,key',
                'task:id,number,title,project_id',
                'task.project:id,key',
                'user:id,name,email',
            ])
            ->orderBy('time_entries.spent_on')
            ->orderBy('time_entries.id');

        // Chronological order is the whole point of a timesheet export, so this pages
        // through the ordered result rather than walking the primary key.
        foreach ($query->lazy(500) as $entry) {
            yield $this->row($entry);
        }
    }

    /**
     * @return list<scalar|null>
     */
    private function row(TimeEntry $entry): array
    {
        $minutes = (int) $entry->minutes;

        return [
            $entry->spent_on?->format('Y-m-d'),
            $entry->project?->name,
            $entry->project?->key,
            $entry->task?->key,
            $entry->task?->title,
            $entry->user?->name,
            $entry->user?->email,
            $minutes,
            round($minutes / 60, 2),
            $entry->is_billable ? __('Yes') : __('No'),
            $entry->is_running ? __('Yes') : __('No'),
            $entry->description,
            $entry->started_at?->format('Y-m-d H:i'),
            $entry->ended_at?->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return Builder<TimeEntry>
     */
    private function query(): Builder
    {
        ['all' => $all, 'own' => $own] = $this->reach();

        $query = TimeEntry::query()
            ->forWorkspace($this->workspace)
            ->where(function (Builder $scope) use ($all, $own): void {
                $scope->whereIn('time_entries.project_id', $all);

                if ($own !== []) {
                    $scope->orWhere(function (Builder $mine) use ($own): void {
                        $mine->whereIn('time_entries.project_id', $own)
                            ->where('time_entries.user_id', $this->reader->getKey());
                    });
                }
            });

        if ($this->filters->from !== null) {
            $query->where('time_entries.spent_on', '>=', $this->filters->from);
        }

        if ($this->filters->to !== null) {
            $query->where('time_entries.spent_on', '<=', $this->filters->to);
        }

        if ($this->filters->billableOnly) {
            $query->where('time_entries.is_billable', true);
        }

        if ($this->filters->assigneeIds !== []) {
            $query->whereIn('time_entries.user_id', $this->filters->assigneeIds);
        }

        $term = trim($this->filters->search);

        if ($term !== '') {
            $query->where('time_entries.description', 'like', '%'.$term.'%');
        }

        return $query;
    }

    /**
     * The projects this export may read in full, and those it may read only the reader's
     * own entries from.
     *
     * @return array{all: list<int>, own: list<int>}
     */
    private function reach(): array
    {
        if ($this->reach !== null) {
            return $this->reach;
        }

        $query = Project::query()
            ->forWorkspace($this->workspace)
            ->visibleTo($this->reader);

        if (! $this->filters->includeArchived) {
            $query->active();
        }

        if ($this->filters->projectIds !== []) {
            $query->whereIn('projects.id', $this->filters->projectIds);
        }

        $all = [];
        $own = [];

        foreach ($query->get(['projects.id', 'projects.workspace_id']) as $project) {
            if (Gate::forUser($this->reader)->allows(Permission::TimeViewAll->value, $project)) {
                $all[] = (int) $project->getKey();
            } else {
                $own[] = (int) $project->getKey();
            }
        }

        return $this->reach = ['all' => $all, 'own' => $own];
    }
}
