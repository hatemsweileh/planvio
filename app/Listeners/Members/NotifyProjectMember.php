<?php

declare(strict_types=1);

namespace App\Listeners\Members;

use App\Events\Members\ProjectMemberAdded;
use App\Models\User;
use App\Notifications\ProjectInvitation;
use App\Services\NotificationDispatcher;

/**
 * Tells somebody they now have a project.
 *
 * For a workspace guest this is not a courtesy: project membership is the only thing that
 * makes the project visible to them at all (ARCHITECTURE.md §4.2), so without this they
 * would have to be told out of band that there is something new to look at.
 */
final class NotifyProjectMember
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function handle(ProjectMemberAdded $event): void
    {
        $member = $event->member->loadMissing('user');
        $user = $member->user;

        if (! $user instanceof User) {
            return;
        }

        $project = $event->project->loadMissing('workspace');

        $this->notifications->send(
            recipients: $user,
            notification: new ProjectInvitation($project, $member->role, $event->actor),
            category: 'project.invitation',
            actor: $event->actor,
            workspace: $project->workspace,
        );
    }
}
