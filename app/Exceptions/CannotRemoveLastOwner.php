<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Workspace;

/**
 * A workspace must always keep at least one owner: it is the only role that can delete the
 * workspace or grant ownership, so losing the last one would strand the tenant.
 */
final class CannotRemoveLastOwner extends DomainException
{
    public static function forWorkspace(Workspace $workspace, int $userId): self
    {
        return new self(
            __('This is the only owner of the workspace. Promote another member to owner first.'),
            [
                'workspace_id' => (int) $workspace->getKey(),
                'user_id' => $userId,
            ],
        );
    }
}
