<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Models\WorkspaceMember;

/**
 * The two ownership rules that {@see RemoveMember} and {@see ChangeMemberRole} both have to
 * hold, kept in one place because holding one of them and not the other still strands a
 * workspace.
 *
 * 1. There is always at least one member with `WorkspaceRole::owner`. Owner is the only role
 *    granted `workspace.delete` and the only one that can promote another owner, so the last
 *    one leaving would lock the tenant permanently.
 *
 * 2. `workspaces.owner_id` always points at somebody who actually holds that role. The column
 *    is what the admin panel and every "contact the owner" path reads; leaving it pointing at
 *    a demoted member makes it a lie.
 */
final class WorkspaceOwnership
{
    /**
     * Whether removing or demoting this user would leave the workspace with no owner.
     */
    public function isLastOwner(Workspace $workspace, int $userId): bool
    {
        $membership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $userId)
            ->first();

        if ($membership === null || $membership->role !== WorkspaceRole::Owner) {
            return false;
        }

        return ! WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', '!=', $userId)
            ->where('role', WorkspaceRole::Owner->value)
            ->exists();
    }

    /**
     * Move `workspaces.owner_id` off $userId when it points at them, choosing the
     * longest-standing remaining owner. A no-op when the column already points elsewhere.
     *
     * Call it *after* the membership change, so the person being demoted is no longer a
     * candidate to inherit the column from themselves.
     */
    public function transferAwayFrom(Workspace $workspace, int $userId): void
    {
        if ((int) $workspace->owner_id !== $userId) {
            return;
        }

        $successor = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', '!=', $userId)
            ->where('role', WorkspaceRole::Owner->value)
            ->orderBy('joined_at')
            ->orderBy('id')
            ->first();

        if ($successor === null) {
            return;
        }

        $workspace->owner_id = $successor->user_id;
        $workspace->save();
    }
}
