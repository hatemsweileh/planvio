<?php

declare(strict_types=1);

namespace App\Actions\Recurring;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Events\Recurring\RecurringTaskOccurrenceGenerated;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * The scheduler entry point: mints every occurrence that has come due.
 *
 * Four things make this safe to run from a cron that may tick twice, overlap itself, or not
 * run for a week:
 *
 *   - **The rule row is locked.** Each rule is processed inside its own transaction with a
 *     `SELECT ... FOR UPDATE` on `recurring_tasks`. Two ticks racing on the same rule are
 *     serialised; the second re-reads a cursor the first has already advanced and finds
 *     nothing to do.
 *   - **Each occurrence is checked for.** Even with no row lock available, a task already
 *     carrying this rule's id and this occurrence date means the occurrence exists — the
 *     cursor advances and nothing is created. That covers a process killed between the
 *     insert and the cursor update, which no lock can.
 *   - **The tenant is bound.** Nothing is bound outside a request, which makes
 *     `WorkspaceScope` inert (ARCHITECTURE.md §3), so every rule is processed inside
 *     `CurrentWorkspace::runFor()` and the queries underneath see one tenant at a time.
 *   - **Catch-up is bounded.** A rule whose cursor is months behind generates at most
 *     {@see self::MAX_CATCH_UP} occurrences per run and picks up the rest on the next tick,
 *     rather than turning one cron tick into a thousand inserts.
 */
final class GenerateDueRecurringTasks
{
    /** Occurrences one rule may produce in a single run. */
    private const MAX_CATCH_UP = 60;

    public function __construct(
        private readonly RecurrenceCalculator $calculator,
        private readonly CreateTask $createTask,
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @return int the number of tasks created
     */
    public function __invoke(DateTimeInterface|string|null $asOf = null, ?Workspace $workspace = null): int
    {
        $today = $asOf === null
            ? RecurrenceSchedule::normalise(CarbonImmutable::now())
            : RecurrenceSchedule::normalise($asOf);

        // The scheduler is system code and runs across every tenant on the server, so the
        // workspace scope is deliberately escaped for this one lookup. Each rule is then
        // handled with its own workspace bound, and only ids are collected here so no cursor
        // is held open across the transactions below.
        $ruleIds = RecurringTask::withoutWorkspaceScope()
            ->dueOn($today)
            ->when(
                $workspace !== null,
                fn ($query) => $query->where('workspace_id', $workspace?->getKey()),
            )
            ->orderBy('id')
            ->pluck('id');

        $created = 0;

        foreach ($ruleIds as $ruleId) {
            $created += $this->runRule((int) $ruleId, $today);
        }

        return $created;
    }

    private function runRule(int $ruleId, CarbonImmutable $today): int
    {
        $workspace = Workspace::query()
            ->whereKey(
                RecurringTask::withoutWorkspaceScope()->whereKey($ruleId)->value('workspace_id'),
            )
            ->first();

        if (! $workspace instanceof Workspace) {
            return 0;
        }

        return (int) $this->currentWorkspace->runFor(
            $workspace,
            fn (): int => DB::transaction(fn (): int => $this->generate($ruleId, $today)),
        );
    }

    private function generate(int $ruleId, CarbonImmutable $today): int
    {
        $rule = RecurringTask::query()->whereKey($ruleId)->lockForUpdate()->first();

        if (! $rule instanceof RecurringTask || ! $rule->is_active || $rule->next_run_on === null) {
            return 0;
        }

        $project = $rule->project()->first();
        $actor = $this->actorFor($rule);

        if (! $project instanceof Project || ! $actor instanceof User) {
            // The rule has outlived what it points at. Deactivating is the honest outcome:
            // leaving it active would have every future tick pick it up and do nothing.
            $rule->forceFill(['is_active' => false, 'next_run_on' => null])->save();

            return 0;
        }

        $schedule = RecurrenceSchedule::fromModel($rule);
        $template = RecurringTaskTemplate::fromArray($rule->template ?? []);

        $cursor = RecurrenceSchedule::normalise($rule->next_run_on);
        $created = 0;
        $steps = 0;

        while ($cursor !== null && ! $cursor->greaterThan($today) && $steps++ < self::MAX_CATCH_UP) {
            if ($this->exhausted($rule, $schedule, $cursor)) {
                $rule->is_active = false;
                $rule->next_run_on = null;
                break;
            }

            $task = $this->occurrence($rule, $project, $actor, $template, $cursor);

            if ($task instanceof Task) {
                $created++;
                $rule->occurrences_generated = (int) $rule->occurrences_generated + 1;

                event(new RecurringTaskOccurrenceGenerated($rule, $task, $cursor));
            }

            $rule->last_run_on = $cursor->toDateString();
            $cursor = $this->calculator->next($schedule, $cursor);
            $rule->next_run_on = $cursor?->toDateString();

            if ($cursor === null) {
                $rule->is_active = false;
                break;
            }
        }

        if ($rule->is_active && $cursor !== null && $this->exhausted($rule, $schedule, $cursor)) {
            $rule->is_active = false;
            $rule->next_run_on = null;
        }

        $rule->save();

        return $created;
    }

    /**
     * Whether the rule has run out of runway before the given occurrence.
     */
    private function exhausted(RecurringTask $rule, RecurrenceSchedule $schedule, CarbonImmutable $cursor): bool
    {
        if ($schedule->endsOn !== null && $cursor->greaterThan($schedule->endsOn)) {
            return true;
        }

        return $schedule->maxOccurrences !== null
            && (int) $rule->occurrences_generated >= $schedule->maxOccurrences;
    }

    /**
     * One occurrence, or null when it already exists.
     *
     * The generated task's `start_date` *is* the occurrence marker: together with
     * `recurring_task_id` it identifies the occurrence uniquely, which is what makes a
     * repeated tick a no-op rather than a duplicate. Trashed tasks count — a deleted
     * occurrence was still generated, and regenerating it would resurrect work someone
     * chose to throw away.
     */
    private function occurrence(
        RecurringTask $rule,
        Project $project,
        User $actor,
        RecurringTaskTemplate $template,
        CarbonImmutable $cursor,
    ): ?Task {
        $exists = Task::withTrashed()
            ->where('recurring_task_id', $rule->getKey())
            ->whereDate('start_date', $cursor->toDateString())
            ->exists();

        if ($exists) {
            return null;
        }

        $task = ($this->createTask)(new CreateTaskData(
            project: $project,
            actor: $actor,
            title: $template->title,
            description: $template->description,
            status: $this->status($template, $project),
            priority: $template->priority,
            assignee: $this->assignee($template, $project),
            reporter: $actor,
            milestone: $this->milestone($template, $project),
            startDate: $cursor,
            dueDate: $template->dueDayOffset === null
                ? null
                : $cursor->addDays(max(0, $template->dueDayOffset)),
            estimateMinutes: $template->estimateMinutes,
            recurringTask: $rule,
        ));

        $this->activity->record($rule, 'occurrence_generated', $actor, [
            'task_id' => (int) $task->getKey(),
            'task_number' => (int) $task->number,
            'occurrence_on' => $cursor->toDateString(),
        ]);

        return $task;
    }

    /**
     * The rule's owner, even if their account has since been soft-deleted: every generated
     * task needs a reporter, and attributing it to the person who set the rule up is more
     * truthful than attributing it to nobody.
     */
    private function actorFor(RecurringTask $rule): ?User
    {
        return User::withTrashed()->whereKey($rule->created_by)->first();
    }

    private function status(RecurringTaskTemplate $template, Project $project): ?TaskStatus
    {
        if ($template->statusId === null) {
            return null;
        }

        // A column that has since been renamed away or deleted falls back to the project
        // default rather than aborting the run.
        return TaskStatus::query()
            ->forProject($project)
            ->whereKey($template->statusId)
            ->first();
    }

    private function milestone(RecurringTaskTemplate $template, Project $project): ?Milestone
    {
        if ($template->milestoneId === null) {
            return null;
        }

        return Milestone::query()
            ->where('project_id', $project->getKey())
            ->whereKey($template->milestoneId)
            ->first();
    }

    /**
     * The templated assignee, but only while they are still in the workspace. Someone who
     * has left would fail the invariant in {@see CreateTask} and stall every future tick, so
     * the occurrence is generated unassigned instead.
     */
    private function assignee(RecurringTaskTemplate $template, Project $project): ?User
    {
        if ($template->assigneeId === null) {
            return null;
        }

        $assignee = User::query()->whereKey($template->assigneeId)->first();

        if (! $assignee instanceof User) {
            return null;
        }

        return $assignee->memberOf($project->workspace) ? $assignee : null;
    }
}
