<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiRun;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A run is one execution of the agent loop under a named acting user. Its author may follow
 * it and stop it; anybody else reading it is auditing, which is `ai.view_logs`.
 */
final class AiRunPolicy
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

    public function view(User $user, AiRun $run): bool
    {
        if ($this->isOwn($user, $run)
            && $this->permits($user, $run->workspace_id, Permission::AiUse)) {
            return true;
        }

        return $this->audits($user, $run);
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::AiUse,
            $project,
        );
    }

    /**
     * Autonomous runs act without a human in the loop each step, so starting one is a
     * separate grant from ordinary assistant use.
     */
    public function runAutonomously(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::AiAutonomous,
            $project,
        );
    }

    /**
     * Stopping a run is a safety control, so it is deliberately easier than starting one:
     * the person who started it, or anyone who administers the AI layer.
     */
    public function cancel(User $user, AiRun $run): bool
    {
        if ($this->isOwn($user, $run)
            && $this->permits($user, $run->workspace_id, Permission::AiUse)) {
            return true;
        }

        return $this->permits($user, $run->workspace_id, Permission::AiManage);
    }

    public function approve(User $user, AiRun $run): bool
    {
        return $this->permits(
            $user,
            $run->workspace_id,
            Permission::AiApprove,
            $this->projectIdOf($run),
        );
    }

    /**
     * Runs are the audit spine of the AI layer; pruning them is retention management, not
     * housekeeping any user does.
     */
    public function delete(User $user, AiRun $run): bool
    {
        return $this->permits($user, $run->workspace_id, Permission::AiManage);
    }

    private function audits(User $user, AiRun $run): bool
    {
        return $this->permits(
            $user,
            $run->workspace_id,
            Permission::AiViewLogs,
            $this->projectIdOf($run),
        );
    }

    private function isOwn(User $user, AiRun $run): bool
    {
        return $run->user_id !== null && (int) $run->user_id === (int) $user->getKey();
    }
}
