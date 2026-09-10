<?php

declare(strict_types=1);

namespace App\Notifications\Milestones;

use App\Jobs\SendDueDateReminders;
use App\Models\Milestone;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A milestone is approaching its date, raised by {@see SendDueDateReminders}.
 *
 * Carries the share of its tasks that are done, because that is the number the recipient is
 * about to go and look up anyway — a milestone reminder without progress is just an alarm.
 */
final class MilestoneDueSoon extends PlanvioNotification
{
    public function __construct(
        private readonly Milestone $milestone,
        private readonly int $daysUntilDue,
        private readonly int $progress,
        private readonly int $openTasks,
    ) {}

    public function category(): string
    {
        return 'milestone.due_soon';
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
            actor: null,
            subjectType: $milestone->getMorphClass(),
            subjectId: (int) $milestone->getKey(),
            extra: [
                'milestone_id' => (int) $milestone->getKey(),
                'milestone_name' => (string) $milestone->name,
                'project_name' => $milestone->project?->name,
                'due_date' => $milestone->due_date?->toDateString(),
                'days_until_due' => $this->daysUntilDue,
                'progress' => $this->progress,
                'open_tasks' => $this->openTasks,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $milestone = $this->context();

        $meta = [
            ['label' => __('Milestone'), 'value' => (string) $milestone->name],
            ['label' => __('Project'), 'value' => (string) ($milestone->project?->name ?? __('None'))],
            ['label' => __('Progress'), 'value' => __(':percent% complete', ['percent' => $this->progress])],
            ['label' => __('Open tasks'), 'value' => (string) $this->openTasks],
        ];

        if ($milestone->due_date !== null) {
            $meta[] = ['label' => __('Due'), 'value' => $milestone->due_date->translatedFormat('l j M Y')];
        }

        return $this->planvioMail(
            subject: __(':milestone — :headline', [
                'milestone' => (string) $milestone->name,
                'headline' => $this->headline(),
            ]),
            title: $this->headline(),
            intro: [(string) $milestone->name],
            meta: $meta,
            actionText: __('Open the milestone'),
            actionUrl: PlanvioUrl::milestone($milestone),
        );
    }

    private function headline(): string
    {
        return match (true) {
            $this->daysUntilDue <= 0 => __('A milestone is due today'),
            $this->daysUntilDue === 1 => __('A milestone is due tomorrow'),
            default => __('A milestone is due in :days days', ['days' => $this->daysUntilDue]),
        };
    }

    private function context(): Milestone
    {
        return $this->milestone->loadMissing(['project', 'workspace']);
    }
}
