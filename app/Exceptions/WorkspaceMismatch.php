<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Two records that must share a tenant do not.
 *
 * Cross-workspace access is a security bug (ARCHITECTURE.md §3), so an Action asked to link
 * records from different workspaces refuses rather than writing the row and leaving the leak
 * for a policy to catch later.
 */
final class WorkspaceMismatch extends DomainException
{
    public static function between(
        string $subject,
        int $subjectWorkspaceId,
        string $related,
        int $relatedWorkspaceId,
    ): self {
        return new self(
            __('Those records belong to different workspaces and cannot be linked.'),
            [
                'subject' => $subject,
                'subject_workspace_id' => $subjectWorkspaceId,
                'related' => $related,
                'related_workspace_id' => $relatedWorkspaceId,
            ],
        );
    }
}
