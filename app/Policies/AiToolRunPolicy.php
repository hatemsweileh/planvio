<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * One tool invocation — the audit spine (ARCHITECTURE.md §5.8). Rows are written by the
 * agent and read by people, so there is no update path at all: an audit record that can be
 * edited is not an audit record.
 */
final class AiToolRunPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::AiViewLogs,
        );
    }

    public function view(User $user, AiToolRun $toolRun): bool
    {
        if ($this->isOwn($user, $toolRun)
            && $this->permits($user, $toolRun->workspace_id, Permission::AiUse)) {
            return true;
        }

        return $this->permits(
            $user,
            $toolRun->workspace_id,
            Permission::AiViewLogs,
            $this->projectIdOf($toolRun),
        );
    }

    /**
     * The approval gate. Note that it is not narrowed to somebody else's tool call: the
     * matrix decides who may approve, and a manager approving a call their own request
     * triggered is the ordinary case, not an escalation.
     */
    public function approve(User $user, AiToolRun $toolRun): bool
    {
        return $this->permits(
            $user,
            $toolRun->workspace_id,
            Permission::AiApprove,
            $this->projectIdOf($toolRun),
        );
    }

    public function reject(User $user, AiToolRun $toolRun): bool
    {
        return $this->approve($user, $toolRun);
    }

    /**
     * Tool runs are written by the agent loop, never by a user.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiToolRun $toolRun): bool
    {
        return false;
    }

    public function delete(User $user, AiToolRun $toolRun): bool
    {
        return $this->permits($user, $toolRun->workspace_id, Permission::AiManage);
    }

    private function isOwn(User $user, AiToolRun $toolRun): bool
    {
        return $toolRun->user_id !== null && (int) $toolRun->user_id === (int) $user->getKey();
    }
}
