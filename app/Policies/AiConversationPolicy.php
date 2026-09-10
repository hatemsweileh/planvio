<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiConversation;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A conversation is the user's own thread with the agent. Reading somebody else's is an
 * audit action and needs `ai.view_logs` — unconditional for an owner or admin, and only
 * inside a managed project for a workspace manager (`+`).
 */
final class AiConversationPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::AiUse,
        );
    }

    public function view(User $user, AiConversation $conversation): bool
    {
        if ($this->isOwn($user, $conversation)
            && $this->permits($user, $conversation->workspace_id, Permission::AiUse)) {
            return true;
        }

        return $this->audits($user, $conversation);
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
     * Continuing a thread writes into it, so it stays with the person whose thread it is —
     * an auditor reads, never contributes under somebody else's name.
     */
    public function update(User $user, AiConversation $conversation): bool
    {
        return $this->isOwn($user, $conversation)
            && $this->permits($user, $conversation->workspace_id, Permission::AiUse);
    }

    public function reply(User $user, AiConversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }

    public function archive(User $user, AiConversation $conversation): bool
    {
        return $this->update($user, $conversation);
    }

    public function delete(User $user, AiConversation $conversation): bool
    {
        if ($this->update($user, $conversation)) {
            return true;
        }

        return $this->permits($user, $conversation->workspace_id, Permission::AiManage);
    }

    public function restore(User $user, AiConversation $conversation): bool
    {
        return $this->delete($user, $conversation);
    }

    public function forceDelete(User $user, AiConversation $conversation): bool
    {
        return $this->permits($user, $conversation->workspace_id, Permission::AiManage);
    }

    private function audits(User $user, AiConversation $conversation): bool
    {
        return $this->permits(
            $user,
            $conversation->workspace_id,
            Permission::AiViewLogs,
            $this->projectIdOf($conversation),
        );
    }

    private function isOwn(User $user, AiConversation $conversation): bool
    {
        return $conversation->user_id !== null
            && (int) $conversation->user_id === (int) $user->getKey();
    }
}
