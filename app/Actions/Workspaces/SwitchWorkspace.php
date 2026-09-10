<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Events\Workspaces\WorkspaceSwitched;
use App\Exceptions\DomainException;
use App\Exceptions\NotAMember;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Session\Session;

/**
 * Moves a user into another workspace: binds the tenant, records the visit, and remembers
 * the choice for the next request.
 *
 * The membership check here is an invariant, not authorization. The caller's policy decided
 * whether this user may switch; what this refuses is a *nonexistent* destination — binding a
 * workspace the user does not belong to would make {@see WorkspaceScope}
 * scope every subsequent query to a tenant whose policies will reject all of it, which reads
 * as an empty product rather than as the error it is.
 *
 * No transaction: the only write is one timestamp, and the session write is not
 * transactional anyway.
 */
final class SwitchWorkspace
{
    /**
     * Session key holding the workspace to rebind on the next request.
     */
    public const SESSION_KEY = 'planvio.workspace_id';

    public function __construct(
        private readonly CurrentWorkspace $currentWorkspace,
        private readonly Session $session,
    ) {}

    public function __invoke(User $user, Workspace $workspace, bool $remember = true): WorkspaceMember
    {
        if ($workspace->trashed() || $workspace->is_suspended) {
            throw new DomainException(
                __('That workspace is no longer available.'),
                ['workspace_id' => (int) $workspace->getKey()],
            );
        }

        $membership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($membership === null) {
            throw NotAMember::ofWorkspace($workspace, (int) $user->getKey());
        }

        $membership->forceFill(['last_active_at' => now()])->save();

        $this->currentWorkspace->set($workspace);

        if ($remember) {
            $this->session->put(self::SESSION_KEY, (int) $workspace->getKey());
        }

        event(new WorkspaceSwitched($workspace, $user));

        return $membership;
    }
}
