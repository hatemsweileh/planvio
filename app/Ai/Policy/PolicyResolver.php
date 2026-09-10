<?php

declare(strict_types=1);

namespace App\Ai\Policy;

use App\Enums\AiMode;
use App\Models\AiPolicy;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Gate;

/**
 * The single entry point that answers "what may this person's agent run do here?".
 *
 * ## Precedence, and the one direction it travels
 *
 * ARCHITECTURE.md §7 and AI_SECURITY.md describe a stack where the most specific rule wins
 * *as long as it narrows*:
 *
 * ```
 * config('ai.enabled')          the installation master switch — outermost, unconditional
 *   └─ ai_settings              the workspace row, else the global row: on/off, kill switch,
 *                               default mode, autonomous flag, execution limits
 *        └─ ai_policies         allow/deny/approval rules, workspace-wide then project
 *             └─ acting user    their workspace role, and whether they may run autonomously
 * ```
 *
 * Each level may only take away. A workspace that switches AI on while
 * `config('ai.enabled')` is false gets nothing. An `AiPolicy` raising `max_risk` to
 * `destructive` in a copilot workspace still cannot execute a mutation unattended, because
 * the mode's ceiling in `config('ai.approvals')` caps it. An `ai_settings` row asking for 500
 * tool calls per run gets the 25 in `config('ai.limits')` (see `RunLimits`). There is no
 * ordering of rows, and no combination of settings, that widens a higher tier — the folds in
 * {@see ResolvedPolicy::resolve()} are all intersections and minimums, and every refusal here
 * returns a fully closed {@see ResolvedPolicy::blocked()} rather than a partially open one.
 *
 * ## Why the acting user is a parameter
 *
 * Because the AI has no authority of its own. The policy stack describes what the *workspace*
 * permits; it is bounded again by what the person the run acts for may do. If they cannot run
 * autonomously, the run degrades to copilot here rather than being refused later — the same
 * narrowing `AiSetting::effectiveMode()` performs for the workspace, applied to the person.
 * Per-tool permissions are deliberately *not* resolved here: those are checked against the
 * record about to be touched, inside the tool, with `Gate::forUser($actingUser)`.
 */
final class PolicyResolver
{
    /**
     * The effective policy for one run: $user acting in $workspace, optionally focused on
     * $project.
     *
     * Reads three tables at most — `ai_settings`, `ai_policies` and the membership lookup —
     * and is called once per run, before any provider call.
     */
    public function resolve(Workspace $workspace, ?Project $project, User $user): ResolvedPolicy
    {
        // 1. The master switch. Nothing below it can turn the AI layer back on.
        if (! (bool) config('ai.enabled')) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_MASTER_SWITCH);
        }

        // 2. The workspace row, or the global default it inherits until it saves its own.
        $settings = $this->settingsFor($workspace);

        if ($settings === null) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_UNCONFIGURED);
        }

        if (! $settings->is_enabled) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_DISABLED, $settings);
        }

        // 3. The acting user has to be an active member before anything else is considered.
        //    Resolved independently of the workspace scope, the way every policy does it.
        $role = $user->is_active ? $user->roleIn($workspace) : null;

        if ($role === null) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_NOT_A_MEMBER, $settings);
        }

        // 4. The kill switch: no new run starts, at any mode, through any surface (§7.7).
        if ($settings->kill_switch_engaged) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_KILL_SWITCH, $settings);
        }

        // 5. The rule stack, folded by intersection and minimum — narrowing only.
        $resolved = ResolvedPolicy::resolve($settings, $this->policiesFor($workspace, $project));

        if (! $resolved->permitsRole($role)) {
            return ResolvedPolicy::blocked(ResolvedPolicy::BLOCKED_ROLE, $settings);
        }

        // 6. The person. Autonomous execution is a separate grant from ordinary AI use, so a
        //    workspace in autonomous mode still runs as copilot for someone without it.
        if ($resolved->mode === AiMode::Autonomous && ! $this->mayRunAutonomously($user, $workspace, $project)) {
            return $resolved->withMode(AiMode::Copilot);
        }

        return $resolved;
    }

    /**
     * The settings row governing $workspace: its own, else the platform default, else null.
     *
     * The provider comes with it because `AiSetting::isUsable()` asks whether it is active,
     * and an authorization-shaped question should not depend on a lazy load succeeding.
     */
    public function settingsFor(Workspace $workspace): ?AiSetting
    {
        return AiSetting::forWorkspace($workspace)?->loadMissing('provider');
    }

    /**
     * The active rule stack for this scope, most significant first.
     *
     * `ai_policies` deliberately carries no workspace scope — platform-wide rows have a null
     * `workspace_id` and the ambient scope would drop exactly the tier that binds everyone —
     * so the workspace is named explicitly by the query.
     *
     * @return list<AiPolicy>
     */
    public function policiesFor(Workspace $workspace, ?Project $project = null): array
    {
        return AiPolicy::query()
            ->applicableTo($workspace, $project)
            ->get()
            ->all();
    }

    /**
     * Whether this person may let the agent act without a human confirming each step.
     *
     * Asked of the Gate as the acting user — the same call the product UI makes — inside the
     * workspace binding, because a class-level check with no record falls back to the bound
     * tenant to find the membership it judges against.
     */
    private function mayRunAutonomously(User $user, Workspace $workspace, ?Project $project): bool
    {
        return Container::getInstance()
            ->make(CurrentWorkspace::class)
            ->runFor($workspace, static fn (): bool => Gate::forUser($user)
                ->allows('runAutonomously', [AiRun::class, $project]));
    }
}
