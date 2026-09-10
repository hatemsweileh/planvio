<?php

declare(strict_types=1);

namespace App\Listeners\Milestones;

use App\Events\Milestones\MilestoneCompleted as MilestoneCompletedEvent;
use App\Models\Milestone;
use App\Models\User;
use App\Notifications\Milestones\MilestoneCompleted as MilestoneCompletedNotification;
use App\Services\NotificationDispatcher;

/**
 * Announces a reached milestone to the project team.
 *
 * The only broadcast notification in the product, and it is one because a milestone is the
 * shared unit of progress — an individual notification about it would be telling one person
 * about something the whole team just achieved.
 *
 * Recipients are the project's explicit members plus the milestone owner. That deliberately
 * stops short of the whole workspace: on a fifty-person install, "someone in accounting hit
 * a milestone" is noise, and the activity feed already carries it.
 */
final class NotifyMilestoneCompleted
{
    public function __construct(private readonly NotificationDispatcher $notifications) {}

    public function handle(MilestoneCompletedEvent $event): void
    {
        $milestone = $event->milestone->loadMissing(['project.members', 'owner', 'workspace']);

        $recipients = $this->team($milestone);

        if ($recipients === []) {
            return;
        }

        $this->notifications->send(
            recipients: $recipients,
            notification: new MilestoneCompletedNotification($milestone, $event->actor),
            category: 'milestone.completed',
            actor: $event->actor,
            workspace: $milestone->workspace,
        );
    }

    /**
     * @return list<User>
     */
    private function team(Milestone $milestone): array
    {
        /** @var array<int, User> $people */
        $people = [];

        $owner = $milestone->owner;

        if ($owner instanceof User) {
            $people[(int) $owner->getKey()] = $owner;
        }

        foreach ($milestone->project?->members ?? [] as $member) {
            if ($member instanceof User) {
                $people[(int) $member->getKey()] = $member;
            }
        }

        return array_values($people);
    }
}
