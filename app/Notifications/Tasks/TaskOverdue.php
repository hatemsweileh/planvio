<?php

declare(strict_types=1);

namespace App\Notifications\Tasks;

use App\Jobs\SendOverdueNotices;
use App\Models\Task;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A task passed its due date and is still open, raised by {@see SendOverdueNotices}.
 *
 * Sent once, on the first day past due, and never again for the same task. A daily nag is
 * how a notification category gets muted, and a muted category is worse than no reminder
 * because it silences the useful ones too.
 */
final class TaskOverdue extends PlanvioNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly int $daysOverdue,
    ) {}

    public function category(): string
    {
        return 'task.overdue';
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
                'days_overdue' => $this->daysOverdue,
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
            $meta[] = ['label' => __('Was due'), 'value' => $task->due_date->translatedFormat('l j M Y')];
        }

        return $this->planvioMail(
            subject: __('[:key] :headline', ['key' => $task->key, 'headline' => $this->headline()]),
            title: $this->headline(),
            intro: [(string) $task->title],
            meta: $meta,
            actionText: __('Open the task'),
            actionUrl: PlanvioUrl::task($task),
            outro: [__('Move the due date or close the task and this stops being overdue.')],
        );
    }

    private function headline(): string
    {
        return $this->daysOverdue <= 1
            ? __('A task is overdue')
            : __('A task is :days days overdue', ['days' => $this->daysOverdue]);
    }

    private function context(): Task
    {
        return $this->task->loadMissing(['project', 'workspace']);
    }
}
