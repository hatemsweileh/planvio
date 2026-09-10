<?php

declare(strict_types=1);

namespace App\Notifications\Milestones;

use App\Models\Milestone;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A milestone was reached.
 *
 * One of the few notifications that is good news, and the only one sent to a whole project
 * team rather than to an individual — which is exactly why it is sent once, when the
 * milestone closes, and never on a re-completion of something already complete.
 */
final class MilestoneCompleted extends PlanvioNotification
{
    public function __construct(
        private readonly Milestone $milestone,
        private readonly ?User $actor,
    ) {}

    public function category(): string
    {
        return 'milestone.completed';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->milestone->workspace_id;
    }

    public function projectId(): ?int
    {
        return (int) $this->milestone->project_id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $milestone = $this->context();

        return $this->payload(
            title: $this->headline(),
            body: (string) $milestone->name,
            url: PlanvioUrl::milestone($milestone),
            actor: $this->actor,
            subjectType: $milestone->getMorphClass(),
            subjectId: (int) $milestone->getKey(),
            extra: [
                'milestone_id' => (int) $milestone->getKey(),
                'milestone_name' => (string) $milestone->name,
                'project_name' => $milestone->project?->name,
                'due_date' => $milestone->due_date?->toDateString(),
                'completed_at' => $milestone->completed_at?->toIso8601String(),
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $milestone = $this->context();

        $meta = [
            ['label' => __('Milestone'), 'value' => (string) $milestone->name],
            ['label' => __('Project'), 'value' => (string) ($milestone->project?->name ?? __('None'))],
        ];

        if ($milestone->due_date !== null) {
            $meta[] = ['label' => __('Target date'), 'value' => $milestone->due_date->translatedFormat('j M Y')];
        }

        if ($milestone->completed_at !== null) {
            $meta[] = ['label' => __('Completed'), 'value' => $milestone->completed_at->translatedFormat('j M Y')];
        }

        return $this->planvioMail(
            subject: __('Milestone reached: :milestone', ['milestone' => (string) $milestone->name]),
            title: $this->headline(),
            intro: [__('That is one more piece of the plan behind you.')],
            meta: $meta,
            actionText: __('Open the milestone'),
            actionUrl: PlanvioUrl::milestone($milestone),
        );
    }

    private function headline(): string
    {
        return $this->actor === null
            ? __('Milestone reached: :milestone', ['milestone' => (string) $this->milestone->name])
            : __(':actor completed the milestone :milestone', [
                'actor' => (string) $this->actor->name,
                'milestone' => (string) $this->milestone->name,
            ]);
    }

    private function context(): Milestone
    {
        return $this->milestone->loadMissing(['project', 'workspace']);
    }
}
