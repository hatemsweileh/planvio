<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Events\Members\InvitationAccepted;
use App\Exceptions\DomainException;
use App\Exceptions\InvitationExpired;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Turns an invitation into a membership.
 *
 * Idempotent by construction, because the link that reaches this action is a URL in an
 * e-mail: it gets clicked twice, prefetched by a mail client, and retried after a timeout.
 * Every one of those must land on the same membership rather than on a second row or an
 * error page — so an already-accepted invitation returns the membership it produced.
 *
 * The address is checked against the signed-in user. The token alone is enough to join, so
 * binding it to the invited address is what stops a forwarded mail from handing a stranger
 * access to somebody else's workspace.
 *
 * An existing membership keeps the role it already has. Somebody who is already an admin and
 * follows a "member" invite stays an admin, and an invitation can never be the thing that
 * raises a role either — role changes go through ChangeMemberRole, where the last-owner rule
 * and the audit trail live.
 */
final class AcceptInvitation
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @throws InvitationExpired
     * @throws DomainException when the invitation was issued to a different address
     */
    public function __invoke(Invitation $invitation, User $user): WorkspaceMember
    {
        $this->assertAddressMatches($invitation, $user);

        $existing = $this->membership($invitation, $user);

        if ($invitation->accepted_at !== null) {
            if ($existing !== null) {
                return $existing;
            }

            // Accepted, but the membership it created is gone: somebody was removed from the
            // workspace after joining. The token must not let them back in on its own.
            throw new DomainException(
                __('This invitation has already been used.'),
                ['invitation_id' => (int) $invitation->getKey()],
            );
        }

        if ($invitation->is_expired) {
            throw InvitationExpired::for($invitation);
        }

        $membership = DB::transaction(function () use ($invitation, $user, $existing): WorkspaceMember {
            $membership = $existing ?? new WorkspaceMember([
                'workspace_id' => $invitation->workspace_id,
                'user_id' => $user->getKey(),
                'role' => $invitation->role ?? WorkspaceRole::Member,
            ]);

            $membership->workspace_id ??= $invitation->workspace_id;
            $membership->user_id ??= $user->getKey();
            $membership->joined_at ??= now();
            $membership->last_active_at = now();
            $membership->save();

            $this->attachToProject($invitation, $user, $membership);

            $invitation->accepted_at = now();
            $invitation->save();

            $this->activity->forUser($user)->log($membership, 'invitation_accepted', [
                'invitation_id' => (int) $invitation->getKey(),
                'role' => $membership->role,
            ]);

            return $membership;
        });

        $user->flushRoleCache();

        event(new InvitationAccepted($invitation, $membership, $user));

        return $membership;
    }

    private function assertAddressMatches(Invitation $invitation, User $user): void
    {
        $invited = mb_strtolower(trim((string) $invitation->email));
        $actual = mb_strtolower(trim((string) $user->email));

        if ($invited !== $actual) {
            throw new DomainException(
                __('This invitation was sent to a different e-mail address.'),
                [
                    'invitation_id' => (int) $invitation->getKey(),
                    'user_id' => (int) $user->getKey(),
                ],
            );
        }
    }

    private function membership(Invitation $invitation, User $user): ?WorkspaceMember
    {
        return WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $invitation->workspace_id)
            ->where('user_id', $user->getKey())
            ->first();
    }

    /**
     * A project-scoped invitation also grants explicit project membership, which is the only
     * thing that makes a project visible to a workspace guest.
     */
    private function attachToProject(Invitation $invitation, User $user, WorkspaceMember $membership): void
    {
        if ($invitation->project_id === null) {
            return;
        }

        $project = Project::query()
            ->withoutWorkspaceScope()
            ->whereKey($invitation->project_id)
            ->first();

        if ($project === null || (int) $project->workspace_id !== (int) $invitation->workspace_id) {
            return;
        }

        ProjectMember::query()->firstOrCreate(
            [
                'project_id' => $project->getKey(),
                'user_id' => $user->getKey(),
            ],
            ['role' => $this->projectRoleFor($membership->role ?? WorkspaceRole::Member)],
        );
    }

    /**
     * The project role an invited workspace role lands on. Guests stay guests; anybody who
     * can already run projects at workspace level arrives as a manager of the one they were
     * invited to.
     */
    private function projectRoleFor(WorkspaceRole $role): ProjectRole
    {
        return match ($role) {
            WorkspaceRole::Guest => ProjectRole::Guest,
            WorkspaceRole::Member => ProjectRole::Member,
            WorkspaceRole::Owner, WorkspaceRole::Admin, WorkspaceRole::Manager => ProjectRole::Manager,
        };
    }
}
