<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AiMode;
use App\Enums\Permission;
use App\Models\AiAutomation;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * An automation is a standing instruction that runs the agent on a schedule or an event, with
 * nobody watching. Reading one is auditing (`ai.view_logs`); creating or changing one is AI
 * administration (`ai.manage`); arming one in autonomous mode additionally needs
 * `ai.autonomous`, because that is the mode with no per-step human decision.
 */
final class AiAutomationPolicy
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

    public function view(User $user, AiAutomation $automation): bool
    {
        return $this->permits(
            $user,
            $automation->workspace_id,
            Permission::AiViewLogs,
            $this->projectIdOf($automation),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::AiManage,
            $project,
        );
    }

    public function update(User $user, AiAutomation $automation): bool
    {
        return $this->manages($user, $automation);
    }

    public function delete(User $user, AiAutomation $automation): bool
    {
        return $this->manages($user, $automation);
    }

    /**
     * Enabling is the moment the automation gains the right to act on its own, so the mode
     * it will run in decides which grant is required.
     */
    public function enable(User $user, AiAutomation $automation): bool
    {
        if (! $this->manages($user, $automation)) {
            return false;
        }

        if ($automation->mode !== AiMode::Autonomous) {
            return true;
        }

        return $this->permits(
            $user,
            $automation->workspace_id,
            Permission::AiAutonomous,
            $this->projectIdOf($automation),
        );
    }

    /**
     * Disabling only ever removes capability, so it stays with plain administration even for
     * an automation somebody else armed.
     */
    public function disable(User $user, AiAutomation $automation): bool
    {
        return $this->manages($user, $automation);
    }

    public function runNow(User $user, AiAutomation $automation): bool
    {
        return $this->enable($user, $automation);
    }

    private function manages(User $user, AiAutomation $automation): bool
    {
        return $this->permits(
            $user,
            $automation->workspace_id,
            Permission::AiManage,
            $this->projectIdOf($automation),
        );
    }
}
