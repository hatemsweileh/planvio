<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AiSetting;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * The per-workspace AI configuration. As with AiPolicy, a null `workspace_id` is the
 * install-wide default: readable by anyone who administers AI in the workspace being
 * browsed, writable only from `/admin`.
 */
final class AiSettingPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::AiManage,
        );
    }

    public function view(User $user, AiSetting $setting): bool
    {
        if ($setting->workspace_id === null) {
            return $this->permitsSomewhere($user, $this->currentWorkspace(), Permission::AiManage);
        }

        return $this->permits($user, $setting->workspace_id, Permission::AiManage);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::AiManage,
        );
    }

    public function update(User $user, AiSetting $setting): bool
    {
        return $this->manages($user, $setting);
    }

    public function delete(User $user, AiSetting $setting): bool
    {
        return $this->manages($user, $setting);
    }

    /**
     * Turning autonomous mode on hands the agent the right to act without a per-step human
     * decision, so it is gated on its own cell rather than on general AI administration.
     */
    public function toggleAutonomous(User $user, AiSetting $setting): bool
    {
        return $this->manages($user, $setting)
            && $this->permits($user, $setting->workspace_id, Permission::AiAutonomous);
    }

    /**
     * The kill switch (ARCHITECTURE.md §7.7). Engaging it only ever removes capability, so
     * it needs no more than the right to administer AI here — and never less, because a
     * workspace must not be able to freeze another's agent.
     */
    public function engageKillSwitch(User $user, AiSetting $setting): bool
    {
        return $this->manages($user, $setting);
    }

    public function releaseKillSwitch(User $user, AiSetting $setting): bool
    {
        return $this->manages($user, $setting);
    }

    private function manages(User $user, AiSetting $setting): bool
    {
        if ($setting->workspace_id === null) {
            return false;
        }

        return $this->permits($user, $setting->workspace_id, Permission::AiManage);
    }
}
