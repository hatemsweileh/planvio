<?php

declare(strict_types=1);

namespace App\Livewire\App\Time\Concerns;

use App\Actions\Time\DeleteTimeEntry;
use App\Actions\Time\LogTime;
use App\Actions\Time\StartTimer;
use App\Actions\Time\StopTimer;
use App\Actions\Time\UpdateTimeEntry;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * Starting, stopping, recording and correcting time.
 *
 * Shared by the timesheet, the project time screen and the standalone timer widget, so all
 * three enforce the same rules in the same order: authorize here, then hand the work to the
 * action, which owns the invariants and the transaction. Nothing in this trait writes a
 * column.
 *
 * ## One clock
 *
 * A person can only be doing one thing at a time, and {@see StartTimer} enforces that by
 * closing whatever was already running — including a timer left open in another workspace,
 * which is precisely the case a workspace-scoped query cannot see. This trait therefore
 * asks the action, not the tenant-scoped table, whether a clock is running, and tells the
 * person when the running one belongs somewhere else. A UI that showed "no timer" while a
 * timer was quietly counting is the fastest way to lose an afternoon.
 *
 * ## Durations are typed, not dialled
 *
 * The duration field accepts what people actually type — `90`, `1:30`, `1h30`, `1.5h`,
 * `45m` — and turns all of it into whole minutes, because `time_entries.minutes` is an
 * integer and every report downstream sums that column.
 */
trait ManagesTime
{
    /** Projects offered in the picker. Past this the command palette is the better tool. */
    private const PROJECT_OPTIONS = 100;

    private const TASK_OPTIONS = 100;

    public string $formDate = '';

    public string $formDuration = '';

    public string $formProject = '';

    public string $formTask = '';

    public string $formDescription = '';

    public bool $formBillable = true;

    /** The entry being corrected, if any. */
    public ?int $editingId = null;

    public bool $formOpen = false;

    abstract protected function timeWorkspace(): Workspace;

    /**
     * The project this screen is locked to, or null when the person may pick one.
     */
    abstract protected function timeProject(): ?Project;

    /* ------------------------------------------------------------------ *
     * The clock
     * ------------------------------------------------------------------ */

    public function timezone(): string
    {
        $user = Auth::user();
        $candidates = [
            $user instanceof User ? $user->timezone : null,
            $this->timeWorkspace()->timezone,
            config('app.timezone', 'UTC'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                try {
                    CarbonImmutable::now($candidate);

                    return $candidate;
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return 'UTC';
    }

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay();
    }

    /**
     * The running entry, if it belongs to this workspace.
     */
    #[Computed]
    public function running(): ?TimeEntry
    {
        $entry = StartTimer::runningFor($this->actor());

        if ($entry === null || (int) $entry->workspace_id !== (int) $this->timeWorkspace()->getKey()) {
            return null;
        }

        return TimeEntry::query()
            ->forWorkspace($this->timeWorkspace())
            ->whereKey($entry->getKey())
            ->with(['project:id,workspace_id,name,key,slug', 'task:id,workspace_id,project_id,number,title'])
            ->first();
    }

    /**
     * A timer running in a different workspace — surfaced rather than hidden, because
     * starting one here would silently stop it.
     */
    #[Computed]
    public function runningElsewhere(): ?TimeEntry
    {
        $entry = StartTimer::runningFor($this->actor());

        return $entry !== null && (int) $entry->workspace_id !== (int) $this->timeWorkspace()->getKey()
            ? $entry
            : null;
    }

    public function startTimer(mixed $projectId = null, mixed $taskId = null): void
    {
        $project = $this->resolveProject($projectId ?? $this->formProject);

        if (! $project instanceof Project) {
            $this->addError('formProject', __('Choose a project to start the timer on.'));

            return;
        }

        $task = $this->resolveTask($taskId ?? $this->formTask, $project);

        $this->authorize('create', [TimeEntry::class, $project]);

        $entry = app(StartTimer::class)(
            $this->actor(),
            $project,
            $task,
            $this->formDescription === '' ? null : $this->formDescription,
            $this->formBillable,
        );

        $this->refreshTime();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __('Timer started on :project', ['project' => $project->name]),
        );
    }

    public function stopTimer(): void
    {
        $entry = $this->running;

        if (! $entry instanceof TimeEntry) {
            return;
        }

        $this->authorize('stop', $entry);

        $stopped = app(StopTimer::class)($entry, $this->actor());

        $this->refreshTime();

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: __('Timer stopped — :duration recorded', [
                'duration' => $this->durationLabel((int) $stopped->minutes),
            ]),
        );
    }

    /* ------------------------------------------------------------------ *
     * Manual entries
     * ------------------------------------------------------------------ */

    public function openEntryForm(?string $date = null): void
    {
        $this->resetEntryForm();

        $this->formDate = $this->parseDate($date)?->toDateString() ?? $this->today()->toDateString();
        $this->formProject = $this->timeProject()?->getKey() !== null
            ? (string) $this->timeProject()?->getKey()
            : '';
        $this->formOpen = true;
    }

    public function editEntry(mixed $entryId): void
    {
        $entry = $this->findEntry($entryId);

        if ($entry === null) {
            return;
        }

        $this->authorize('update', $entry);

        if ($entry->is_running) {
            $this->dispatch(
                'planvio-notify',
                type: 'error',
                message: __('Stop the timer before correcting this entry.'),
            );

            return;
        }

        $this->editingId = (int) $entry->getKey();
        $this->formDate = $entry->spent_on?->format('Y-m-d') ?? $this->today()->toDateString();
        $this->formDuration = $this->durationLabel((int) $entry->minutes);
        $this->formProject = (string) $entry->project_id;
        $this->formTask = $entry->task_id === null ? '' : (string) $entry->task_id;
        $this->formDescription = (string) ($entry->description ?? '');
        $this->formBillable = (bool) $entry->is_billable;
        $this->formOpen = true;

        unset($this->taskOptions);
    }

    public function cancelEntry(): void
    {
        $this->resetEntryForm();
        $this->formOpen = false;
    }

    public function updatedFormOpen(bool $open): void
    {
        if (! $open) {
            $this->resetEntryForm();
        }
    }

    public function updatedFormProject(): void
    {
        $this->formTask = '';

        unset($this->taskOptions);
    }

    public function saveEntry(): void
    {
        $minutes = self::parseMinutes($this->formDuration);

        if ($minutes === null) {
            $this->addError('formDuration', __('Enter a duration such as 90, 1:30, 1h 30m or 1.5h.'));

            return;
        }

        if ($minutes < 1 || $minutes > LogTime::MAX_MINUTES) {
            $this->addError('formDuration', __('A single entry has to be between one minute and 24 hours.'));

            return;
        }

        $project = $this->resolveProject($this->formProject);

        if (! $project instanceof Project) {
            $this->addError('formProject', __('Choose a project.'));

            return;
        }

        $date = $this->parseDate($this->formDate);

        if ($date === null) {
            $this->addError('formDate', __('Choose the day the work was done.'));

            return;
        }

        $task = $this->resolveTask($this->formTask, $project);
        $description = trim($this->formDescription) === '' ? null : trim($this->formDescription);

        try {
            if ($this->editingId !== null) {
                $entry = $this->findEntry($this->editingId);

                if ($entry === null) {
                    return;
                }

                $this->authorize('update', $entry);

                app(UpdateTimeEntry::class)(
                    $entry,
                    $this->actor(),
                    $minutes,
                    $date->toDateString(),
                    $description,
                    $this->formBillable,
                    $task,
                );

                $message = __('Entry updated');
            } else {
                $this->authorize('create', [TimeEntry::class, $project]);

                app(LogTime::class)(
                    $this->actor(),
                    $project,
                    $minutes,
                    $date->toDateString(),
                    $task,
                    $description,
                    $this->formBillable,
                );

                $message = __(':duration logged on :project', [
                    'duration' => $this->durationLabel($minutes),
                    'project' => $project->name,
                ]);
            }
        } catch (DomainException $exception) {
            // The actions refuse for stated reasons — a future date, a task in another
            // project — and the sentence they wrote is the one to show.
            $this->addError('formDuration', $exception->getMessage());

            return;
        }

        $this->resetEntryForm();
        $this->formOpen = false;
        $this->refreshTime();

        $this->dispatch('planvio-notify', type: 'success', message: $message);
    }

    public function deleteEntry(mixed $entryId): void
    {
        $entry = $this->findEntry($entryId);

        if ($entry === null) {
            return;
        }

        $this->authorize('delete', $entry);

        app(DeleteTimeEntry::class)($entry, $this->actor());

        if ($this->editingId === (int) $entry->getKey()) {
            $this->resetEntryForm();
            $this->formOpen = false;
        }

        $this->refreshTime();

        $this->dispatch('planvio-notify', type: 'success', message: __('Entry deleted'));
    }

    private function resetEntryForm(): void
    {
        $this->editingId = null;
        $this->formDuration = '';
        $this->formTask = '';
        $this->formDescription = '';
        $this->formBillable = true;

        $this->resetErrorBag();

        unset($this->taskOptions);
    }

    /* ------------------------------------------------------------------ *
     * Options
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function projectOptions(): array
    {
        $scoped = $this->timeProject();

        if ($scoped instanceof Project) {
            return [(string) $scoped->getKey() => (string) $scoped->name];
        }

        $options = ['' => __('Choose a project')];

        $projects = Project::query()
            ->forWorkspace($this->timeWorkspace())
            ->visibleTo($this->actor())
            ->active()
            ->orderBy('name')
            ->limit(self::PROJECT_OPTIONS)
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
    public function taskOptions(): array
    {
        $options = ['' => __('No task — project level')];

        $project = $this->resolveProject($this->formProject);

        if (! $project instanceof Project) {
            return $options;
        }

        $tasks = Task::query()
            ->forWorkspace($this->timeWorkspace())
            ->where('tasks.project_id', $project->getKey())
            ->whereNull('tasks.completed_at')
            ->with(['project:id,workspace_id,name,key,slug'])
            ->orderBy('tasks.due_date')
            ->orderByDesc('tasks.id')
            ->limit(self::TASK_OPTIONS)
            ->get();

        foreach ($tasks as $task) {
            $options[(string) $task->getKey()] = $task->key.' · '.$task->title;
        }

        return $options;
    }

    /* ------------------------------------------------------------------ *
     * Parsing and formatting
     * ------------------------------------------------------------------ */

    /**
     * Whole minutes from whatever somebody typed, or null when it is not a duration.
     *
     * Accepts `90`, `1:30`, `1h30`, `1h 30m`, `1.5h`, `45m`, `0.75` (hours only when a unit
     * says so — a bare number is minutes, because that is what a timesheet field means).
     */
    public static function parseMinutes(string $input): ?int
    {
        $value = strtolower(trim($input));

        if ($value === '') {
            return null;
        }

        // 1:30
        if (preg_match('/^(\d{1,3}):([0-5]?\d)$/', $value, $m) === 1) {
            return (int) $m[1] * 60 + (int) $m[2];
        }

        // 1h, 1h30, 1h 30m, 1.5h, 90m
        if (preg_match('/^(?:(\d+(?:[.,]\d+)?)\s*h)?\s*(?:(\d+)\s*m?)?$/', $value, $m) === 1
            && ($m[1] ?? '') !== '') {
            $hours = (float) str_replace(',', '.', $m[1]);
            $minutes = ($m[2] ?? '') === '' ? 0 : (int) $m[2];

            return (int) round($hours * 60) + $minutes;
        }

        // 45m
        if (preg_match('/^(\d+)\s*m$/', $value, $m) === 1) {
            return (int) $m[1];
        }

        // A bare number is minutes.
        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    public function durationLabel(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $hours === 0
            ? $rest.'m'
            : ($rest === 0 ? $hours.'h' : $hours.'h '.$rest.'m');
    }

    protected function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value, $this->timezone());

        return $date === false ? null : $date->startOfDay();
    }

    /* ------------------------------------------------------------------ *
     * Resolution
     * ------------------------------------------------------------------ */

    private function resolveProject(mixed $projectId): ?Project
    {
        $scoped = $this->timeProject();

        if ($scoped instanceof Project) {
            return $scoped;
        }

        $id = is_numeric($projectId) ? (int) $projectId : 0;

        if ($id < 1) {
            return null;
        }

        return Project::query()
            ->forWorkspace($this->timeWorkspace())
            ->visibleTo($this->actor())
            ->whereKey($id)
            ->first();
    }

    private function resolveTask(mixed $taskId, Project $project): ?Task
    {
        $id = is_numeric($taskId) ? (int) $taskId : 0;

        if ($id < 1) {
            return null;
        }

        return Task::query()
            ->forWorkspace($this->timeWorkspace())
            ->where('tasks.project_id', $project->getKey())
            ->whereKey($id)
            ->first();
    }

    protected function findEntry(mixed $entryId): ?TimeEntry
    {
        $id = is_numeric($entryId) ? (int) $entryId : 0;

        if ($id < 1) {
            return null;
        }

        $project = $this->timeProject();

        return TimeEntry::query()
            ->forWorkspace($this->timeWorkspace())
            ->when(
                $project instanceof Project,
                fn (Builder $query): Builder => $query->where('time_entries.project_id', $project?->getKey()),
            )
            ->whereKey($id)
            ->with([
                'project:id,workspace_id,name,key,slug',
                'task:id,workspace_id,project_id,number,title',
                'user:id,name,avatar_path',
            ])
            ->first();
    }

    protected function actor(): User
    {
        $user = Auth::user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    /**
     * Drop everything this screen derived from `time_entries`.
     *
     * The shared caches are cleared here so no component can forget one; each screen adds
     * its own through {@see self::refreshTimeData()}.
     */
    protected function refreshTime(): void
    {
        unset($this->running, $this->runningElsewhere, $this->taskOptions, $this->projectOptions);

        $this->refreshTimeData();
    }

    abstract protected function refreshTimeData(): void;
}
