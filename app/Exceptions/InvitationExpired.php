<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Invitation;

/**
 * An invitation token is a bearer credential, so it stops working at its expiry rather than
 * living forever in an inbox.
 */
final class InvitationExpired extends DomainException
{
    public static function for(Invitation $invitation): self
    {
        return new self(
            __('This invitation has expired. Ask an administrator to send a new one.'),
            [
                'invitation_id' => (int) $invitation->getKey(),
                'workspace_id' => (int) $invitation->workspace_id,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        );
    }
}
