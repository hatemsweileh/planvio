<?php

declare(strict_types=1);

namespace App\Notifications\Tasks;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a watcher that a task they follow moved to another board column.
 *
 * A first placement has no `from` — a task created directly into a column, or one whose
 * previous column was deleted — so the message drops to "is now in Review" rather than
 * inventing a starting point.
 */
final class TaskStatusChanged extends PlanvioNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly ?TaskStatus $from,
        private readonly TaskStatus $to,
        private readonly User $actor,
    ) {}

    public function category(): string
    {
        return 'task.status_changed';
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
            actor: $this->actor,
            subjectType: $task->getMorphClass(),
            subjectId: (int) $task->getKey(),
            extra: [
                'task_id' => (int) $task->getKey(),
                'task_key' => $task->key,
                'task_title' => (string) $task->title,
                'project_name' => $task->project?->name,
                'from_status_id' => $this->from === null ? null : (int) $this->from->getKey(),
                'from_status_name' => $this->from?->name,
                'to_status_id' => (int) $this->to->getKey(),
                'to_status_name' => (string) $this->to->name,
                'to_status_category' => $this->to->category->value,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $task = $this->context();

        $meta = [
            ['label' => __('Task'), 'value' => $task->key.' · '.(string) $task->title],
            ['label' => __('Project'), 'value' => (string) ($task->project?->name ?? __('None'))],
        ];

        if ($this->from !== null) {
            $meta[] = ['label' => __('Was'), 'value' => (string) $this->from->name];
        }

        $meta[] = ['label' => __('Now'), 'value' => (string) $this->to->name];

        return $this->planvioMail(
            subject: __('[:key] :status', ['key' => $task->key, 'status' => (string) $this->to->name]),
            title: $this->headline(),
            intro: [(string) $task->title],
            meta: $meta,
            actionText: __('Open the task'),
            actionUrl: PlanvioUrl::task($task),
        );
    }

    private function headline(): string
    {
        if ($this->from === null) {
            return __(':actor moved a task to :to', [
                'actor' => (string) $this->actor->name,
                'to' => (string) $this->to->name,
            ]);
        }

        return __(':actor moved a task from :from to :to', [
            'actor' => (string) $this->actor->name,
            'from' => (string) $this->from->name,
            'to' => (string) $this->to->name,
        ]);
    }

    private function context(): Task
    {
        return $this->task->loadMissing(['project', 'workspace']);
    }
}
