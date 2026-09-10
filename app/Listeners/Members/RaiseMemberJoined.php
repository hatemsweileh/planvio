<?php

declare(strict_types=1);

namespace App\Listeners\Members;

use App\Events\Members\InvitationAccepted;
use App\Events\Members\MemberJoined;
use App\Models\Workspace;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;

/**
 * Translates "an invitation was accepted" into "the workspace has one more person".
 *
 * The two are not the same event even though today one always follows the other: an
 * administrator adding somebody directly, or the installer creating the first owner, produces
 * a new member without an invitation. Anything downstream that cares about team size —
 * webhooks especially — subscribes to {@see MemberJoined} and keeps working when those other
 * paths appear.
 */
final class RaiseMemberJoined
{
    public function __construct(private readonly EventDispatcher $events) {}

    public function handle(InvitationAccepted $event): void
    {
        $member = $event->member->loadMissing('workspace');
        $workspace = $member->workspace ?? $event->invitation->loadMissing('workspace')->workspace;

        if (! $workspace instanceof Workspace) {
            return;
        }

        $this->events->dispatch(new MemberJoined(
            workspace: $workspace,
            member: $member,
            user: $event->user,
            invitation: $event->invitation,
        ));
    }
}
