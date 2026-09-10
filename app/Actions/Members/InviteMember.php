<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\WorkspaceRole;
use App\Events\Members\MemberInvited;
use App\Exceptions\AlreadyAMember;
use App\Exceptions\DomainException;
use App\Exceptions\WorkspaceMismatch;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\WorkspaceInvitation;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Creates a pending invitation and hands the delivery to the queue.
 *
 * The mail is not sent here. Sending inline would put an SMTP round trip inside the request
 * that created the row — and on the shared hosting Planvio targets, an unreachable mail
 * server would then look like a broken invite button.
 *
 * Re-inviting the same address refreshes the invitation that is already pending instead of
 * creating a second one. Two live tokens for one address means one of them is a credential
 * nobody is tracking, and revoking the invitation would only revoke half of it.
 */
final class InviteMember
{
    /**
     * Days an invitation stays valid when nothing is configured. Long enough to survive a
     * holiday, short enough that a forwarded mailbox is not a standing door.
     */
    private const DEFAULT_EXPIRY_DAYS = 14;

    /**
     * `invitations.token` is varchar(64) unique.
     */
    private const TOKEN_LENGTH = 64;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @throws AlreadyAMember when the address already belongs to a member of the workspace
     * @throws WorkspaceMismatch when the project belongs to a different workspace
     */
    public function __invoke(
        Workspace $workspace,
        User $inviter,
        string $email,
        WorkspaceRole $role = WorkspaceRole::Member,
        ?Project $project = null,
    ): Invitation {
        $address = mb_strtolower(trim($email));

        if ($address === '' || ! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException(__('That is not a valid e-mail address.'), ['email' => $address]);
        }

        if ($project !== null && (int) $project->workspace_id !== (int) $workspace->getKey()) {
            throw WorkspaceMismatch::between(
                'project',
                (int) $project->workspace_id,
                'workspace',
                (int) $workspace->getKey(),
            );
        }

        $this->assertNotAlreadyMember($workspace, $address);

        $invitation = DB::transaction(function () use ($workspace, $inviter, $address, $role, $project): Invitation {
            $invitation = $this->pendingInvitation($workspace, $address, $project);

            if ($invitation === null) {
                $invitation = new Invitation;
                $invitation->workspace_id = $workspace->getKey();
                $invitation->email = $address;
                $invitation->project_id = $project?->getKey();
                $invitation->token = $this->freshToken();
            } elseif ($invitation->is_expired) {
                // Reviving a lapsed invitation issues a new token. The old one has been
                // sitting in an inbox past its date; extending it would quietly undo the
                // expiry that was the point of having one.
                $invitation->token = $this->freshToken();
            }

            $invitation->role = $role;
            $invitation->invited_by = $inviter->getKey();
            $invitation->expires_at = now()->addDays($this->expiryDays());
            $invitation->accepted_at = null;
            $invitation->save();

            $this->activity->forUser($inviter)->log($invitation, 'invited', [
                'email' => $address,
                'role' => $role,
                'project_id' => $project?->getKey(),
            ]);

            return $invitation;
        });

        // Queued, and held back until the transaction above commits — the worker would
        // otherwise look for a row that has not been written yet.
        Notification::route('mail', $address)->notify(new WorkspaceInvitation($invitation));

        event(new MemberInvited($invitation, $inviter));

        return $invitation;
    }

    private function assertNotAlreadyMember(Workspace $workspace, string $address): void
    {
        $existing = User::query()->where('email', $address)->first();

        if ($existing === null) {
            return;
        }

        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $existing->getKey())
            ->exists();

        if ($isMember) {
            throw AlreadyAMember::ofWorkspace($workspace, $address);
        }
    }

    /**
     * The live invitation for this address, if there is one. Scoped by project as well as by
     * address: a project-level guest invite and a workspace invite to the same person are
     * two different offers.
     */
    private function pendingInvitation(Workspace $workspace, string $address, ?Project $project): ?Invitation
    {
        return Invitation::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->forEmail($address)
            ->when(
                $project === null,
                static fn (Builder $query): Builder => $query->whereNull('project_id'),
                static fn (Builder $query): Builder => $query->where('project_id', $project?->getKey()),
            )
            ->whereNull('accepted_at')
            ->orderByDesc('id')
            ->first();
    }

    private function freshToken(): string
    {
        do {
            $token = Str::random(self::TOKEN_LENGTH);
        } while (Invitation::withoutWorkspaceScope()->where('token', $token)->exists());

        return $token;
    }

    private function expiryDays(): int
    {
        $configured = (int) config('planvio.invitations.expiry_days', self::DEFAULT_EXPIRY_DAYS);

        return $configured > 0 ? $configured : self::DEFAULT_EXPIRY_DAYS;
    }
}
