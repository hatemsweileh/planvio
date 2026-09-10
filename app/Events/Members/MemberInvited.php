<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Models\Invitation;
use App\Models\User;

/**
 * An invitation was created or an existing pending one was refreshed.
 *
 * The event deliberately does not carry the token: listeners that need to build a link
 * read it from the invitation, and nothing else should ever see it.
 */
final readonly class MemberInvited
{
    public function __construct(
        public Invitation $invitation,
        public User $inviter,
    ) {}
}
