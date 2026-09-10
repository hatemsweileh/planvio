<?php

declare(strict_types=1);

namespace App\Livewire\App\Recurring;

use App\Actions\Recurring\CreateRecurringTask;
use App\Actions\Recurring\DeleteRecurringTask;
use App\Actions\Recurring\InvalidRecurrence;
use App\Actions\Recurring\RecurrenceCalculator;
use App\Actions\Recurring\RecurrenceSchedule;
use App\Actions\Recurring\RecurringTaskTemplate;
use App\Actions\Recurring\UpdateRecurringTask;
use App\Enums\Priority;
use App\Enums\RecurrenceFrequency;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

/**
 * The standing instructions on a project: what repeats, how often, and when it next fires.
 *
 * ## Why the preview is not decoration
 *
 * A recurrence rule is the one piece of configuration in Planvio whose effect is entirely in
 * the future. "Every 2 months on the 31st" is easy to type and hard to picture, and the
 * difference between what somebody meant and what they wrote only shows up weeks later as
 * tasks appearing on the wrong days. So the next five occurrences are computed live, by
 * {@see RecurrenceCalculator} — the same class the scheduler runs — and shown beside the
 * form. A preview from a second implementation would be worse than none: it would be
 * believed.
 *
 * ## Pause, don't delete
 *
 * Pausing keeps the rule and its cursor; {@see UpdateRecurringTask} recomputes `next_run_on`
 * on resume so a rule switched back on after a month does not immediately mint the month of
 * backlog it was paused to avoid. Deleting is for a rule that was a mistake, and it leaves
 * the tasks it already produced alone — they are real work.
 *
 * ## Authorization
 *
 * A recurrence writes tasks unattended, long after its author has moved on, so creating and
 * editing one is project configuration (`project.update`) rather than the `task.create` any
 * member holds. Every action here authorises before it touches an Action.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Occurrences shown in the preview. */
    private const PREVIEW = 5;

    public Workspace $workspace;

    public Project $project;

    /** The rule being edited, or null while creating. */
    public ?int $editingId = null;

    public bool $showingForm = false;

    public ?int $confirmingDeleteId = null;

    /* --- the template ------------------------------------------------- */

    public string $title = '';

    public string $description = '';

    public string $priority = 'medium';

    public ?int $assigneeId = null;

    public ?int $statusId = null;

    public ?int $milestoneId = null;

    public string $estimate = '';

    public string $dueDayOffset = '';

    /* --- the schedule ------------------------------------------------- */

    public string $frequency = 'weekly';

    public int $interval = 1;

    /** @var list<int> ISO weekdays, 1 = Monday */
    public array $weekdays = [];

    /** @var list<int> */
    public array $monthdays = [];

    public string $startsOn = '';

    public string $endsOn = '';

    public string $maxOccurrences = '';

    public bool $isActive = true;

    public function mount(Workspace $workspace, Project $project): void
    {
        $this->authorize('view', $project);
        $this->authorize('viewAny', [RecurringTask::class, $project]);

        $this->workspace = $workspace;
        $this->project = $project;
        $this->startsOn = $this->today()->toDateString();
    }

    public function render(): View
    {
        return view('livewire.app.recurring.index')
            ->title($this->project->name.' · '.__('Recurring tasks'));
    }

    /* ------------------------------------------------------------------ *
     * The list
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, RecurringTask>
     */
    #[Computed]
    public function rules(): EloquentCollection
    {
        return RecurringTask::query()
            ->forWorkspace($this->workspace)
            ->forProject($this->project)
            ->with(['creator:id,name,avatar_path'])
            ->withCount('tasks')
            ->orderByDesc('is_active')
            ->orderByRaw('next_run_on is null')
            ->orderBy('next_run_on')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * A rule in one sentence: "Every 2 weeks on Mon, Thu".
     */
    public function summarise(RecurringTask $rule): string
    {
        $interval = max(1, (int) $rule->interval);

        $base = match ($rule->frequency) {
            RecurrenceFrequency::Daily => trans_choice('{1}Every day|[2,*]Every :count days', $interval, ['count' => $interval]),
            RecurrenceFrequency::Weekly => trans_choice('{1}Every week|[2,*]Every :count weeks', $interval, ['count' => $interval]),
            RecurrenceFrequency::Monthly => trans_choice('{1}Every month|[2,*]Every :count months', $interval, ['count' => $interval]),
            RecurrenceFrequency::Yearly => trans_choice('{1}Every year|[2,*]Every :count years', $interval, ['count' => $interval]),
            RecurrenceFrequency::Custom => trans_choice('{1}Every day|[2,*]Every :count days', $interval, ['count' => $interval]),
        };

        $weekdays = is_array($rule->by_weekday) ? $rule->by_weekday : [];
        $monthdays = is_array($rule->by_monthday) ? $rule->by_monthday : [];

        if ($weekdays !== []) {
            $base .= ' · '.implode(', ', array_map(
                fn (mixed $day): string => $this->weekdayLabel((int) $day),
                $weekdays,
            ));
        }

        if ($monthdays !== []) {
            $base .= ' · '.__('on the :days', ['days' => implode(', ', array_map(intval(...), $monthdays))]);
        }

        return $base;
    }

    /**
     * The days a stored rule will actually fire next.
     *
     * It starts from the rule's own cursor rather than from its start date. A rule that
     * began in March and has been running since would otherwise advertise March: what the
     * scheduler will do next is written in `next_run_on`, and a list that disagreed with the
     * cursor would be a list nobody could trust.
     *
     * @return list<CarbonImmutable>
     */
    public function upcomingFor(RecurringTask $rule, int $count = 3): array
    {
        $schedule = RecurrenceSchedule::fromModel($rule);
        $generated = (int) $rule->occurrences_generated;

        if ($rule->next_run_on === null) {
            return $this->occurrences(
                $schedule,
                $count,
                $generated,
                $rule->last_run_on === null ? null : RecurrenceSchedule::normalise($rule->last_run_on),
            );
        }

        // The cursor itself is the first occurrence; the walk continues from the day before
        // it so `next()` returns it rather than the one after.
        return $this->occurrences(
            $schedule,
            $count,
            $generated,
            RecurrenceSchedule::normalise($rule->next_run_on)->subDay(),
        );
    }

    /* ------------------------------------------------------------------ *
     * The form
     * ------------------------------------------------------------------ */

    public function create(): void
    {
        $this->authorize('create', [RecurringTask::class, $this->project]);

        $this->resetForm();
        $this->editingId = null;
        $this->showingForm = true;
    }

    public function edit(int $id): void
    {
        $rule = $this->findRule($id);

        $this->authorize('update', $rule);

        $template = RecurringTaskTemplate::fromArray($rule->template ?? []);

        $this->editingId = (int) $rule->getKey();
        $this->title = $template->title;
        $this->description = $template->description ?? '';
        $this->priority = $template->priority->value;
        $this->assigneeId = $template->assigneeId;
        $this->statusId = $template->statusId;
        $this->milestoneId = $template->milestoneId;
        $this->estimate = $template->estimateMinutes === null ? '' : (string) round($template->estimateMinutes / 60, 2);
        $this->dueDayOffset = $template->dueDayOffset === null ? '' : (string) $template->dueDayOffset;

        $this->frequency = $rule->frequency->value;
        $this->interval = max(1, (int) $rule->interval);
        $this->weekdays = array_map(intval(...), is_array($rule->by_weekday) ? $rule->by_weekday : []);
        $this->monthdays = array_map(intval(...), is_array($rule->by_monthday) ? $rule->by_monthday : []);
        $this->startsOn = $rule->starts_on?->format('Y-m-d') ?? $this->today()->toDateString();
        $this->endsOn = $rule->ends_on?->format('Y-m-d') ?? '';
        $this->maxOccurrences = $rule->max_occurrences === null ? '' : (string) $rule->max_occurrences;
        $this->isActive = (bool) $rule->is_active;

        $this->showingForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showingForm = false;
        $this->editingId = null;
        $this->resetErrorBag();
    }

    public function toggleWeekday(int $day): void
    {
        if ($day < 1 || $day > 7) {
            return;
        }

        $this->weekdays = $this->toggle($this->weekdays, $day);
    }

    public function toggleMonthday(int $day): void
    {
        if ($day < 1 || $day > 31) {
            return;
        }

        $this->monthdays = $this->toggle($this->monthdays, $day);
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', 'string'],
            'interval' => ['required', 'integer', 'min:1', 'max:365'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'maxOccurrences' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'estimate' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'dueDayOffset' => ['nullable', 'integer', 'min:0', 'max:365'],
        ], [
            'endsOn.after_or_equal' => __('The rule cannot end before it starts.'),
        ]);

        $actor = $this->actor();
        $template = $this->template();
        $schedule = $this->schedule();

        if ($schedule === null) {
            $this->addError('frequency', __('This rule can never fire. Loosen the days it is restricted to.'));

            return;
        }

        try {
            if ($this->editingId === null) {
                $this->authorize('create', [RecurringTask::class, $this->project]);

                app(CreateRecurringTask::class)($this->project, $template, $schedule, $actor, $this->isActive);
            } else {
                $rule = $this->findRule($this->editingId);
                $this->authorize('update', $rule);

                app(UpdateRecurringTask::class)($rule, $actor, $template, $schedule, $this->isActive);
            }
        } catch (InvalidRecurrence $exception) {
            $this->addError('frequency', $exception->getMessage());

            return;
        }

        $this->showingForm = false;
        $this->editingId = null;
        unset($this->rules);

        $this->dispatch('planvio-notify', type: 'success', message: __('Recurrence saved.'));
    }

    public function toggleActive(int $id): void
    {
        $rule = $this->findRule($id);

        $this->authorize('toggle', $rule);

        app(UpdateRecurringTask::class)($rule, $this->actor(), isActive: ! $rule->is_active);

        unset($this->rules);

        $this->dispatch(
            'planvio-notify',
            type: 'success',
            message: $rule->is_active ? __('Recurrence resumed.') : __('Recurrence paused.'),
        );
    }

    public function confirmDelete(int $id): void
    {
        $this->authorize('delete', $this->findRule($id));

        $this->confirmingDeleteId = $id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeleteId = null;
    }

    public function delete(): void
    {
        if ($this->confirmingDeleteId === null) {
            return;
        }

        $rule = $this->findRule($this->confirmingDeleteId);

        $this->authorize('delete', $rule);

        app(DeleteRecurringTask::class)($rule, $this->actor());

        $this->confirmingDeleteId = null;

        if ($this->editingId === (int) $rule->getKey()) {
            $this->cancel();
        }

        unset($this->rules);

        $this->dispatch('planvio-notify', type: 'success', message: __('Recurrence deleted. The tasks it created are untouched.'));
    }

    /* ------------------------------------------------------------------ *
     * The preview
     * ------------------------------------------------------------------ */

    /**
     * The next five dates the rule as currently typed would fire on.
     *
     * @return list<CarbonImmutable>
     */
    #[Computed]
    public function preview(): array
    {
        $schedule = $this->schedule();

        return $schedule === null ? [] : $this->occurrences($schedule, self::PREVIEW, 0, null);
    }

    /**
     * Why the preview is empty, when it is.
     */
    public function previewProblem(): ?string
    {
        if ($this->schedule() === null) {
            return __('These settings do not describe a schedule Planvio can read.');
        }

        if ($this->preview !== []) {
            return null;
        }

        if ($this->endsOn !== '' && $this->endsOn < $this->startsOn) {
            return __('The end date is before the start date, so nothing can ever fire.');
        }

        return __('No date satisfies every restriction — a weekday and a month day that never coincide, for instance.');
    }

    /**
     * Walk the calculator forward, stopping where the rule itself would stop.
     *
     * @return list<CarbonImmutable>
     */
    private function occurrences(
        RecurrenceSchedule $schedule,
        int $count,
        int $alreadyGenerated,
        ?CarbonImmutable $after,
    ): array {
        $calculator = app(RecurrenceCalculator::class);
        $dates = [];

        $cursor = $after === null
            ? $calculator->first($schedule)
            : $calculator->next($schedule, $after);

        while ($cursor !== null && count($dates) < $count) {
            if ($schedule->maxOccurrences !== null
                && $alreadyGenerated + count($dates) >= $schedule->maxOccurrences) {
                break;
            }

            $dates[] = $cursor;
            $cursor = $calculator->next($schedule, $cursor);
        }

        return $dates;
    }

    /* ------------------------------------------------------------------ *
     * Form state to domain objects
     * ------------------------------------------------------------------ */

    private function template(): RecurringTaskTemplate
    {
        $estimate = trim($this->estimate);
        $offset = trim($this->dueDayOffset);

        return new RecurringTaskTemplate(
            title: trim($this->title),
            description: trim($this->description) === '' ? null : trim($this->description),
            priority: Priority::tryFrom($this->priority) ?? Priority::Medium,
            assigneeId: $this->memberOptions->contains('id', $this->assigneeId) ? $this->assigneeId : null,
            statusId: $this->statusOptions->contains('id', $this->statusId) ? $this->statusId : null,
            milestoneId: $this->milestoneOptions->contains('id', $this->milestoneId) ? $this->milestoneId : null,
            estimateMinutes: $estimate === '' ? null : (int) round(((float) $estimate) * 60),
            dueDayOffset: $offset === '' ? null : (int) $offset,
        );
    }

    /**
     * Null when the values on screen cannot make a schedule at all — an out-of-range
     * weekday, or a date the calendar does not have.
     */
    private function schedule(): ?RecurrenceSchedule
    {
        $frequency = RecurrenceFrequency::tryFrom($this->frequency) ?? RecurrenceFrequency::Weekly;

        // Weekday and month-day restrictions only mean something for the frequencies that
        // read them; carrying a stale selection into a daily rule would silently narrow it.
        $weekdays = in_array($frequency, [RecurrenceFrequency::Weekly, RecurrenceFrequency::Custom], true)
            ? $this->weekdays
            : [];

        $monthdays = in_array($frequency, [RecurrenceFrequency::Monthly, RecurrenceFrequency::Custom], true)
            ? $this->monthdays
            : [];

        try {
            return RecurrenceSchedule::make(
                frequency: $frequency,
                startsOn: $this->startsOn === '' ? $this->today() : $this->startsOn,
                interval: max(1, min(365, $this->interval)),
                byWeekday: $weekdays,
                byMonthday: $monthdays,
                endsOn: $this->endsOn === '' ? null : $this->endsOn,
                maxOccurrences: trim($this->maxOccurrences) === '' ? null : (int) $this->maxOccurrences,
            );
        } catch (Throwable) {
            // Two different refusals, both meaning "not a schedule yet": InvalidRecurrence
            // for a day out of range, and Carbon's own for a date somebody is halfway
            // through typing. The preview renders on every keystroke, so neither may be an
            // exception on screen — the caller turns a null into a sentence instead.
            return null;
        }
    }

    private function resetForm(): void
    {
        $this->reset([
            'title', 'description', 'assigneeId', 'statusId', 'milestoneId',
            'estimate', 'dueDayOffset', 'endsOn', 'maxOccurrences',
        ]);

        $this->priority = Priority::Medium->value;
        $this->frequency = RecurrenceFrequency::Weekly->value;
        $this->interval = 1;
        $this->weekdays = [$this->today()->dayOfWeekIso];
        $this->monthdays = [];
        $this->startsOn = $this->today()->toDateString();
        $this->isActive = true;
        $this->resetErrorBag();
    }

    /* ------------------------------------------------------------------ *
     * Options and labels
     * ------------------------------------------------------------------ */

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function memberOptions(): EloquentCollection
    {
        return $this->workspace->members()
            ->where('users.is_active', true)
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.avatar_path']);
    }

    /**
     * @return EloquentCollection<int, TaskStatus>
     */
    #[Computed]
    public function statusOptions(): EloquentCollection
    {
        return TaskStatus::query()->forProject($this->project)->ordered()->get();
    }

    /**
     * @return EloquentCollection<int, Milestone>
     */
    #[Computed]
    public function milestoneOptions(): EloquentCollection
    {
        return Milestone::query()->forProject($this->project)->ordered()->get(['id', 'name']);
    }

    /**
     * @return array<string, string>
     */
    public function frequencyOptions(): array
    {
        return RecurrenceFrequency::options();
    }

    /**
     * @return array<string, string>
     */
    public function priorityOptions(): array
    {
        return Priority::options();
    }

    public function showsWeekdays(): bool
    {
        return in_array($this->frequency, [RecurrenceFrequency::Weekly->value, RecurrenceFrequency::Custom->value], true);
    }

    public function showsMonthdays(): bool
    {
        return in_array($this->frequency, [RecurrenceFrequency::Monthly->value, RecurrenceFrequency::Custom->value], true);
    }

    public function weekdayLabel(int $iso): string
    {
        // Monday is 1 in ISO numbering, and 8 January 2024 was a Monday.
        return CarbonImmutable::create(2024, 1, 7 + $iso, 0, 0, 0, 'UTC')?->translatedFormat('D') ?? (string) $iso;
    }

    public function intervalUnit(): string
    {
        return match (RecurrenceFrequency::tryFrom($this->frequency)) {
            RecurrenceFrequency::Weekly => trans_choice('{1}week|[2,*]weeks', $this->interval),
            RecurrenceFrequency::Monthly => trans_choice('{1}month|[2,*]months', $this->interval),
            RecurrenceFrequency::Yearly => trans_choice('{1}year|[2,*]years', $this->interval),
            default => trans_choice('{1}day|[2,*]days', $this->interval),
        };
    }

    public function today(): CarbonImmutable
    {
        $timezone = (string) ($this->workspace->timezone ?? '');

        return CarbonImmutable::now($timezone === '' ? 'UTC' : $timezone)->startOfDay();
    }

    /* ------------------------------------------------------------------ *
     * Plumbing
     * ------------------------------------------------------------------ */

    private function findRule(int $id): RecurringTask
    {
        // Scoped through the project's own relation, so an id from another project — or
        // another workspace — is a 404 rather than a policy question about the wrong row.
        return RecurringTask::query()
            ->forWorkspace($this->workspace)
            ->forProject($this->project)
            ->whereKey($id)
            ->firstOrFail();
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function toggle(array $values, int $value): array
    {
        $index = array_search($value, $values, true);

        if ($index === false) {
            $values[] = $value;
        } else {
            unset($values[$index]);
        }

        sort($values);

        return array_values($values);
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }
}
