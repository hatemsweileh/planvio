<?php

declare(strict_types=1);

namespace App\Notifications\Tasks;

use App\Listeners\Tasks\NotifyTaskCompleted;
use App\Models\Task;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use App\Support\Formats;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A task closed.
 *
 * This is the *outcome* signal, not the board-movement one: it goes to the people who asked
 * for the work — the reporter, the creator, the milestone owner — rather than to everyone
 * watching the card. Watchers already had {@see TaskStatusChanged} for the same move, and
 * {@see NotifyTaskCompleted} subtracts them so nobody is told twice
 * about one click.
 */
final class TaskCompleted extends PlanvioNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly ?User $actor,
    ) {}

    public function category(): string
    {
        return 'task.completed';
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
                'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
                'completed_at' => $task->completed_at?->toIso8601String(),
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

        if ($task->completed_at !== null) {
            // The comma between the date and the clock is punctuation, and Arabic sets it as ،.
            $meta[] = [
                'label' => __('Completed'),
                'value' => $task->completed_at->translatedFormat(Formats::punctuate('j M Y, H:i')),
            ];
        }

        return $this->planvioMail(
            subject: __('[:key] Completed', ['key' => $task->key]),
            title: $this->headline(),
            intro: [(string) $task->title],
            meta: $meta,
            actionText: __('Open the task'),
            actionUrl: PlanvioUrl::task($task),
        );
    }

    private function headline(): string
    {
        return $this->actor === null
            ? __('A task you asked for is done')
            : __(':actor completed a task you asked for', ['actor' => (string) $this->actor->name]);
    }

    private function context(): Task
    {
        return $this->task->loadMissing(['project', 'workspace']);
    }
}
