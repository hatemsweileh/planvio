<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Milestones\MilestoneDueSoon;
use App\Notifications\Tasks\TaskDueSoon;
use App\Services\NotificationDispatcher;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The "this is coming up" pass, over tasks and milestones alike.
 *
 * **Why it runs hourly and still sends once a day.** Workspaces choose their own timezone,
 * so there is no single hour at which a daily reminder is morning for everybody. The
 * scheduler ticks every hour; each workspace is skipped unless its *local* clock has just
 * reached `config('planvio.reminders.digest_hour')`. One tick per workspace per day falls
 * out of that, in the workspace's own morning, with no per-record bookkeeping to keep
 * consistent — and `withoutOverlapping()` on the schedule stops a slow run being joined by
 * the next one.
 *
 * **Why the tenant is bound per workspace.** Nothing is bound outside a request, which makes
 * `WorkspaceScope` inert (ARCHITECTURE.md §3). A reminder job that queried without binding
 * would happily read every tenant's tasks at once, and the first bug in the filter would be
 * a cross-tenant leak. Each workspace is processed inside `CurrentWorkspace::runFor()`, so
 * the queries underneath see exactly one tenant.
 *
 * There is no actor to exclude here: nobody did anything, a date arrived.
 */
final class SendDueDateReminders implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Tasks loaded at a time. Shared hosting will not thank us for a 10,000-row hydrate. */
    private const CHUNK = 200;

    /**
     * @param bool $force send regardless of the local hour — for `--now` and tests
     * @param int|null $workspaceId limit the run to one workspace
     */
    public function __construct(
        private readonly bool $force = false,
        private readonly ?int $workspaceId = null,
    ) {}

    /**
     * @return int the number of people notified
     */
    public function handle(CurrentWorkspace $workspaces, NotificationDispatcher $notifications): int
    {
        $leadTimes = $this->leadTimes();

        if ($leadTimes === []) {
            return 0;
        }

        $sent = 0;

        foreach ($this->eligibleWorkspaces() as $workspace) {
            $today = $this->localToday($workspace);

            if ($today === null) {
                continue;
            }

            $sent += (int) $workspaces->runFor(
                $workspace,
                fn (): int => $this->forWorkspace($workspace, $today, $leadTimes, $notifications),
            );
        }

        return $sent;
    }

    /**
     * @param list<int> $leadTimes
     */
    private function forWorkspace(
        Workspace $workspace,
        CarbonImmutable $today,
        array $leadTimes,
        NotificationDispatcher $notifications,
    ): int {
        $sent = 0;

        foreach ($leadTimes as $days) {
            $due = $today->addDays($days);

            $sent += $this->remindAboutTasks($workspace, $due, $days, $notifications);
            $sent += $this->remindAboutMilestones($workspace, $due, $days, $notifications);
        }

        return $sent;
    }

    private function remindAboutTasks(
        Workspace $workspace,
        CarbonImmutable $due,
        int $days,
        NotificationDispatcher $notifications,
    ): int {
        $sent = 0;

        Task::query()
            // dueBetween() rather than whereDate(): a bare date comparison reads straight off
            // index(workspace_id, due_date), and it is exact whether the driver stored the
            // column as a date (MySQL) or as "Y-m-d H:i:s" (SQLite).
            ->dueBetween($due, $due)
            ->whereNotNull('assignee_id')
            ->whereNull('completed_at')
            // The completion flag on the status is authoritative; `completed_at` alone would
            // include a task dragged into "Cancelled" without the timestamp being written.
            ->whereHas('status', fn (Builder $query): Builder => $query->where('is_completed', false))
            ->with(['assignee', 'project', 'workspace'])
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($tasks) use (&$sent, $days, $workspace, $notifications): void {
                foreach ($tasks as $task) {
                    $assignee = $task->assignee;

                    if (! $assignee instanceof User) {
                        continue;
                    }

                    $sent += $notifications->send(
                        recipients: $assignee,
                        notification: new TaskDueSoon($task, $days),
                        category: 'task.due_soon',
                        // No actor: without one, the dispatcher's "never notify the actor"
                        // rule has nobody to exclude, which is exactly right for a reminder
                        // a person may well have set for themselves.
                        actor: null,
                        workspace: $workspace,
                    );
                }
            });

        return $sent;
    }

    private function remindAboutMilestones(
        Workspace $workspace,
        CarbonImmutable $due,
        int $days,
        NotificationDispatcher $notifications,
    ): int {
        $milestones = Milestone::query()
            // Same half-open range as the task query above, for the same two reasons.
            ->where('due_date', '>=', $due->toDateString())
            ->where('due_date', '<', $due->addDay()->toDateString())
            ->whereNull('completed_at')
            ->open()
            ->with(['owner', 'project.owner', 'project.manager', 'workspace'])
            ->orderBy('id')
            ->get();

        $sent = 0;

        foreach ($milestones as $milestone) {
            $recipients = $this->milestoneAudience($milestone);

            if ($recipients === []) {
                continue;
            }

            $sent += $notifications->send(
                recipients: $recipients,
                notification: new MilestoneDueSoon(
                    $milestone,
                    $days,
                    (int) $milestone->task_progress,
                    $this->openTaskCount($milestone),
                ),
                category: 'milestone.due_soon',
                actor: null,
                workspace: $workspace,
            );
        }

        return $sent;
    }

    /**
     * The people accountable for a milestone: its owner, and the project's owner and manager.
     * Not the whole team — a date slipping is a management signal, not a broadcast.
     *
     * @return list<User>
     */
    private function milestoneAudience(Milestone $milestone): array
    {
        /** @var array<int, User> $people */
        $people = [];

        $project = $milestone->project;

        foreach ([$milestone->owner, $project?->owner, $project instanceof Project ? $project->manager : null] as $user) {
            if ($user instanceof User) {
                $people[(int) $user->getKey()] = $user;
            }
        }

        return array_values($people);
    }

    private function openTaskCount(Milestone $milestone): int
    {
        return $milestone->tasks()
            ->whereHas('status', fn (Builder $query): Builder => $query->where('is_completed', false))
            ->count();
    }

    /**
     * @return list<int> days of notice, largest first, deduplicated
     */
    private function leadTimes(): array
    {
        $configured = config('planvio.reminders.due_soon_days', [3, 1]);

        if (! is_array($configured)) {
            return [];
        }

        $days = [];

        foreach ($configured as $value) {
            $day = (int) $value;

            if ($day >= 0 && ! in_array($day, $days, true)) {
                $days[] = $day;
            }
        }

        rsort($days);

        return $days;
    }

    /**
     * @return iterable<Workspace>
     */
    private function eligibleWorkspaces(): iterable
    {
        return Workspace::query()
            ->active()
            ->when($this->workspaceId !== null, fn (Builder $query): Builder => $query->whereKey($this->workspaceId))
            ->orderBy('id')
            ->cursor();
    }

    /**
     * Today in the workspace's own timezone, or null when this is not its digest hour.
     */
    private function localToday(Workspace $workspace): ?CarbonImmutable
    {
        $timezone = is_string($workspace->timezone) && $workspace->timezone !== ''
            ? $workspace->timezone
            : 'UTC';

        $now = CarbonImmutable::now($timezone);

        if (! $this->force && $now->hour !== $this->digestHour()) {
            return null;
        }

        return $now->startOfDay();
    }

    private function digestHour(): int
    {
        $hour = (int) config('planvio.reminders.digest_hour', 8);

        return max(0, min(23, $hour));
    }
}
