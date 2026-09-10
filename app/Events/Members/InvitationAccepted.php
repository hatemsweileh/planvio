<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Models\Invitation;
use App\Models\User;
use App\Models\WorkspaceMember;

/**
 * Somebody joined a workspace through an invitation link.
 */
final readonly class InvitationAccepted
{
    public function __construct(
        public Invitation $invitation,
        public WorkspaceMember $member,
        public User $user,
    ) {}
}
