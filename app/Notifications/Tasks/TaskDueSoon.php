<?php

declare(strict_types=1);

namespace App\Notifications\Tasks;

use App\Jobs\SendDueDateReminders;
use App\Models\Task;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A reminder that a task is coming due, raised by {@see SendDueDateReminders}
 * on the lead times in `config('planvio.reminders.due_soon_days')`.
 *
 * There is no actor: nobody did anything, a date arrived. That is why the reminder jobs
 * never have to worry about the never-notify-the-actor rule — there is no one to exclude,
 * and the recipient is the assignee, who is the only person a deadline is actionable for.
 */
final class TaskDueSoon extends PlanvioNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly int $daysUntilDue,
    ) {}

    public function category(): string
    {
        return 'task.due_soon';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->task->workspace_id;
    }

    public function projectId(): ?int
    {
        return (int) $this->task->project_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $task = $this->context();

        return $this->payload(
            title: $this->headline(),
            body: $task->key.' · '.(string) $task->title,
            url: PlanvioUrl::task($task),
            actor: null,
            subjectType: $task->getMorphClass(),
            subjectId: (int) $task->getKey(),
            extra: [
                'task_id' => (int) $task->getKey(),
                'task_key' => $task->key,
                'task_title' => (string) $task->title,
                'project_name' => $task->project?->name,
                'priority' => $task->priority->value,
                'due_date' => $task->due_date?->toDateString(),
                'days_until_due' => $this->daysUntilDue,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->context();

        $meta = [
            ['label' => __('Task'), 'value' => $task->key.' · '.(string) $task->title],
            ['label' => __('Project'), 'value' => (string) ($task->project?->name ?? __('None'))],
            ['label' => __('Priority'), 'value' => $task->priority->label()],
        ];

        if ($task->due_date !== null) {
            $meta[] = ['label' => __('Due'), 'value' => $task->due_date->translatedFormat('l j M Y')];
        }

        return $this->planvioMail(
            subject: __('[:key] :headline', ['key' => $task->key, 'headline' => $this->headline()]),
            title: $this->headline(),
            intro: [(string) $task->title],
            meta: $meta,
            actionText: __('Open the task'),
            actionUrl: PlanvioUrl::task($task),
            outro: [__('If this is no longer yours, reassign it and the reminders stop.')],
        );
    }

    private function headline(): string
    {
        return match (true) {
            $this->daysUntilDue <= 0 => __('A task is due today'),
            $this->daysUntilDue === 1 => __('A task is due tomorrow'),
            default => __('A task is due in :days days', ['days' => $this->daysUntilDue]),
        };
    }

    private function context(): Task
    {
        return $this->task->loadMissing(['project', 'workspace']);
    }
}
