<?php

declare(strict_types=1);

namespace App\Notifications\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Somebody handed this person a piece of work.
 *
 * Goes to the new assignee only. The person who did the assigning already knows, and the
 * previous assignee gets nothing — "a task was taken off you" is a message that reads as an
 * accusation however it is worded, and the activity feed records the change either way.
 */
final class TaskAssigned extends PlanvioNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly User $actor,
        private readonly ?User $previous = null,
    ) {}

    public function category(): string
    {
        return 'task.assigned';
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
            title: __(':actor assigned you a task', ['actor' => (string) $this->actor->name]),
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
                'priority' => $task->priority->value,
                'due_date' => $task->due_date?->toDateString(),
                'previous_assignee_id' => $this->previous === null ? null : (int) $this->previous->getKey(),
                'previous_assignee_name' => $this->previous?->name,
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
            $meta[] = ['label' => __('Due'), 'value' => $task->due_date->translatedFormat('j M Y')];
        }

        return $this->planvioMail(
            subject: __('[:key] :title', ['key' => $task->key, 'title' => (string) $task->title]),
            title: __(':actor assigned you a task', ['actor' => (string) $this->actor->name]),
            intro: [__('It is yours now — here is what it says.')],
            meta: $meta,
            actionText: __('Open the task'),
            actionUrl: PlanvioUrl::task($task),
        );
    }

    private function context(): Task
    {
        return $this->task->loadMissing(['project', 'workspace']);
    }
}
