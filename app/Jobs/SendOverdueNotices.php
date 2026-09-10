<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Tasks\TaskOverdue;
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
 * The "this has slipped" pass.
 *
 * Notices go out on the exact anniversaries in `config('planvio.reminders.overdue_days')` —
 * one day past due, then a week — rather than every day a task stays open. That is the whole
 * design of this job: a daily nag is how a category gets muted, and a muted category takes
 * the reminders somebody actually wanted with it. Matching an exact age also makes the job
 * naturally idempotent, since a task is only ever one day old once.
 *
 * Like {@see SendDueDateReminders} it runs hourly and acts on each workspace only at that
 * workspace's local digest hour, and binds the tenant per workspace so the queries
 * underneath see one tenant at a time (ARCHITECTURE.md §3).
 */
final class SendOverdueNotices implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const CHUNK = 200;

    public function __construct(
        private readonly bool $force = false,
        private readonly ?int $workspaceId = null,
    ) {}

    /**
     * @return int the number of people notified
     */
    public function handle(CurrentWorkspace $workspaces, NotificationDispatcher $notifications): int
    {
        if (config('planvio.reminders.send_overdue', true) !== true) {
            return 0;
        }

        $ages = $this->noticeAges();

        if ($ages === []) {
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
                fn (): int => $this->forWorkspace($workspace, $today, $ages, $notifications),
            );
        }

        return $sent;
    }

    /**
     * @param list<int> $ages
     */
    private function forWorkspace(
        Workspace $workspace,
        CarbonImmutable $today,
        array $ages,
        NotificationDispatcher $notifications,
    ): int {
        $sent = 0;

        foreach ($ages as $days) {
            $wasDue = $today->subDays($days);

            Task::query()
                // dueBetween() rather than whereDate(): a bare date comparison reads straight
                // off index(workspace_id, due_date), and it is exact whether the driver stored
                // the column as a date (MySQL) or as "Y-m-d H:i:s" (SQLite).
                ->dueBetween($wasDue, $wasDue)
                ->whereNotNull('assignee_id')
                ->whereNull('completed_at')
                ->whereHas('status', fn (Builder $query): Builder => $query->where('is_completed', false))
                ->with(['assignee', 'project', 'workspace'])
                ->chunkById(self::CHUNK, function ($tasks) use (&$sent, $days, $workspace, $notifications): void {
                    foreach ($tasks as $task) {
                        $assignee = $task->assignee;

                        if (! $assignee instanceof User) {
                            continue;
                        }

                        $sent += $notifications->send(
                            recipients: $assignee,
                            notification: new TaskOverdue($task, $days),
                            category: 'task.overdue',
                            actor: null,
                            workspace: $workspace,
                        );
                    }
                });
        }

        return $sent;
    }

    /**
     * @return list<int> days past due at which a notice is sent, ascending
     */
    private function noticeAges(): array
    {
        $configured = config('planvio.reminders.overdue_days', [1]);

        if (! is_array($configured)) {
            return [1];
        }

        $ages = [];

        foreach ($configured as $value) {
            $age = (int) $value;

            if ($age >= 1 && ! in_array($age, $ages, true)) {
                $ages[] = $age;
            }
        }

        sort($ages);

        return $ages;
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
        return max(0, min(23, (int) config('planvio.reminders.digest_hour', 8)));
    }
}
