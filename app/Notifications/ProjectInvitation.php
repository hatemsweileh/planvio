<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Somebody was given access to a project.
 *
 * The counterpart to {@see WorkspaceInvitation}, and deliberately a different notification
 * rather than a variant of it. A workspace invitation goes to an address that may not have
 * an account and carries a bearer token; this one goes to an existing member who can already
 * sign in, carries no credential, and so belongs in the in-app inbox as well as in mail.
 *
 * For a workspace guest this is the notification that matters most: their project membership
 * is the only thing that makes the project visible to them at all (ARCHITECTURE.md §4.2).
 */
final class ProjectInvitation extends PlanvioNotification
{
    public function __construct(
        private readonly Project $project,
        private readonly ProjectRole $role,
        private readonly ?User $actor,
    ) {}

    public function category(): string
    {
        return 'project.invitation';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->project->workspace_id;
    }

    public function projectId(): ?int
    {
        return (int) $this->project->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $project = $this->context();

        return $this->payload(
            title: $this->headline(),
            body: (string) $project->name,
            url: PlanvioUrl::project($project),
            actor: $this->actor,
            subjectType: $project->getMorphClass(),
            subjectId: (int) $project->getKey(),
            extra: [
                'project_id' => (int) $project->getKey(),
                'project_name' => (string) $project->name,
                'project_key' => (string) $project->key,
                'role' => $this->role->value,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $project = $this->context();

        $meta = [
            ['label' => __('Project'), 'value' => (string) $project->name],
            ['label' => __('Your role'), 'value' => $this->role->label()],
        ];

        if ($this->actor !== null) {
            $meta[] = ['label' => __('Added by'), 'value' => (string) $this->actor->name];
        }

        if ($project->target_date !== null) {
            $meta[] = ['label' => __('Target date'), 'value' => $project->target_date->translatedFormat('j M Y')];
        }

        return $this->planvioMail(
            subject: __('You have been added to :project', ['project' => (string) $project->name]),
            title: $this->headline(),
            intro: [__('You can see its board, its tasks and its files from now on.')],
            meta: $meta,
            actionText: __('Open the project'),
            actionUrl: PlanvioUrl::project($project),
        );
    }

    private function headline(): string
    {
        return $this->actor === null
            ? __('You have been added to :project', ['project' => (string) $this->project->name])
            : __(':actor added you to :project', [
                'actor' => (string) $this->actor->name,
                'project' => (string) $this->project->name,
            ]);
    }

    private function context(): Project
    {
        return $this->project->loadMissing('workspace');
    }
}
