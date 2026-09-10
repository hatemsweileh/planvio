<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Models\Invitation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * A workspace has one more person in it.
 *
 * Deliberately broader than {@see InvitationAccepted}: somebody can arrive through an
 * invitation link, through an administrator adding them directly, or through the installer
 * creating the first owner. Listeners that care about "the team changed" — webhooks,
 * seat counting, the welcome notification — want all of those, and should not have to
 * subscribe to three events to get them.
 *
 * `invitation` is null when the person did not arrive through one.
 */
final readonly class MemberJoined
{
    public function __construct(
        public Workspace $workspace,
        public WorkspaceMember $member,
        public User $user,
        public ?Invitation $invitation = null,
    ) {}
}
