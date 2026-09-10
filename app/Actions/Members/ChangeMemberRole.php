<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\WorkspaceRole;
use App\Events\Members\MemberRoleChanged;
use App\Exceptions\CannotRemoveLastOwner;
use App\Exceptions\NotAMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Moves a member from one workspace role to another.
 *
 * Demoting the last owner is refused: owner is the only role granted `workspace.delete` and
 * the only one that can create another owner, so a workspace without one can never be
 * recovered from inside the product.
 *
 * Demoting the member recorded in `workspaces.owner_id` is allowed as long as somebody else
 * holds the role — the column follows them out and is handed to the longest-standing
 * remaining owner.
 */
final class ChangeMemberRole
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WorkspaceOwnership $ownership,
    ) {}

    /**
     * @throws NotAMember
     * @throws CannotRemoveLastOwner
     */
    public function __invoke(
        Workspace $workspace,
        User $member,
        WorkspaceRole $role,
        ?User $actor = null,
    ): WorkspaceMember {
        $membership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $member->getKey())
            ->first();

        if ($membership === null) {
            throw NotAMember::ofWorkspace($workspace, (int) $member->getKey());
        }

        $previous = $membership->role ?? WorkspaceRole::Member;

        if ($previous === $role) {
            return $membership;
        }

        if ($role !== WorkspaceRole::Owner && $this->ownership->isLastOwner($workspace, (int) $member->getKey())) {
            throw CannotRemoveLastOwner::forWorkspace($workspace, (int) $member->getKey());
        }

        DB::transaction(function () use ($workspace, $membership, $member, $role, $previous, $actor): void {
            $membership->role = $role;
            $membership->save();

            if ($role !== WorkspaceRole::Owner) {
                $this->ownership->transferAwayFrom($workspace, (int) $member->getKey());
            }

            $this->activity->forUser($actor)->log($membership, 'role_changed', [
                'user_id' => (int) $member->getKey(),
                'changes' => [
                    'role' => ['old' => $previous->value, 'new' => $role->value],
                ],
            ]);
        });

        $member->flushRoleCache();

        event(new MemberRoleChanged($membership, $previous, $role, $actor));

        return $membership;
    }
}
