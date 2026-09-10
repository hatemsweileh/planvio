<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Agent\AgentContext;
use App\Enums\Permission;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * The single answer to "may the AI run at all, right now, here, for this person?"
 *
 * Everything that can start a run — the chat surface, the API, the automation tick, a run
 * resumed after an approval — asks this first. Having exactly one implementation is the whole
 * point: a second copy of these checks, written slightly differently, is how a kill switch
 * ends up honoured on three surfaces out of four.
 *
 * The order below runs cheapest-first, but it is also an order of *authority*. The platform
 * switch outranks the workspace's own setting, the workspace's setting outranks the person's
 * permission, and the permission outranks the budget. A refusal returns the first reason
 * found, so whoever reads it is told about the outermost problem rather than a symptom of it.
 *
 * ## What passing here does not grant
 *
 * Nothing. This answers whether a run may *start*; every tool call inside that run is still
 * authorised individually through `Gate::forUser($actingUser)` against the record it touches
 * ({@see AgentContext}). The AI has no authority of its own at any point
 * (AI_SECURITY.md, "The single most important property").
 *
 * ## No memoisation
 *
 * Answers are recomputed on every question. A kill switch engaged mid-tick has to take effect
 * at the next thing that asks, and two indexed reads per run start is a trade worth making for
 * that.
 */
final class AiGate
{
    /* ------------------------------------------------------------------ *
     * The question, asked two ways
     * ------------------------------------------------------------------ */

    /**
     * Whether $user may start an AI run in $workspace.
     */
    public function allows(Workspace $workspace, User $user): bool
    {
        return $this->refusal($workspace, $user) === null;
    }

    /**
     * Why $user may not start a run, or null when they may.
     *
     * The string is translated and safe everywhere it goes: shown to the person who triggered
     * the run, written to `ai_runs.error`, and written to a log. It names a setting or a limit
     * and never a credential, an endpoint or another tenant's data (CLAUDE.md rule 4).
     */
    public function refusal(Workspace $workspace, User $user): ?string
    {
        $refusal = $this->workspaceRefusal($workspace);

        if ($refusal !== null) {
            return $refusal;
        }

        // The acting user's own authority, asked of the Gate exactly the way the product UI
        // asks it. Someone who cannot use AI by hand cannot use it by proxy.
        if (! Gate::forUser($user)->allows(Permission::AiUse->value, $workspace)) {
            return __('ai.gate.not_permitted');
        }

        $settings = $this->settingsFor($workspace);

        if ($settings === null) {
            return __('ai.gate.not_configured');
        }

        return $this->budgetRefusal($workspace, $user, $settings);
    }

    /**
     * Whether the workspace is in a state where AI may run at all, ignoring who is asking.
     *
     * This is the half of the question an automation tick can answer before it has looked at
     * an acting user, and the half the banner in the product UI is showing.
     */
    public function workspaceAllows(Workspace $workspace): bool
    {
        return $this->workspaceRefusal($workspace) === null;
    }

    /**
     * Why no run may start in $workspace, or null when one may.
     */
    public function workspaceRefusal(Workspace $workspace): ?string
    {
        // The outermost gate. With this off there is no AI route, job, tool or provider call
        // anywhere in the application, whatever a workspace has saved (config/ai.php).
        if (! (bool) config('ai.enabled', false)) {
            return __('ai.gate.disabled_globally');
        }

        // A suspended workspace is one an administrator has switched off. Spending provider
        // budget on it would be the one thing suspension is supposed to prevent.
        if ($workspace->is_suspended) {
            return __('ai.gate.workspace_suspended');
        }

        $settings = $this->settingsFor($workspace);

        if ($settings === null) {
            return __('ai.gate.not_configured');
        }

        if (! $settings->is_enabled) {
            return __('ai.gate.disabled_for_workspace');
        }

        if ($settings->kill_switch_engaged) {
            return __('ai.gate.kill_switch');
        }

        $provider = $settings->provider;

        if ($provider === null) {
            return __('ai.gate.no_provider');
        }

        if (! $provider->is_active) {
            return __('ai.gate.provider_inactive');
        }

        return null;
    }

    /* ------------------------------------------------------------------ *
     * Settings
     * ------------------------------------------------------------------ */

    /**
     * The AI settings governing $workspace — its own row, or the global default it inherits.
     *
     * The provider relation is eager-loaded here rather than left to a lazy read: every caller
     * needs it, and lazy loading is an exception outside production.
     */
    public function settingsFor(Workspace $workspace): ?AiSetting
    {
        $settings = AiSetting::forWorkspace($workspace);

        return $settings?->loadMissing('provider');
    }

    /* ------------------------------------------------------------------ *
     * Budget
     * ------------------------------------------------------------------ */

    /**
     * The two spend guards, checked last because each one costs a counting query.
     *
     * The hourly cap is per *person* and platform-wide rather than per workspace: the budget
     * it protects is the installation's provider bill, and a user who belongs to six
     * workspaces would otherwise hold six times the allowance. The daily cap is the
     * workspace's own `ai_settings.max_runs_per_day` — the number an administrator sets when
     * they decide what they are willing to pay for (AI_SECURITY.md, administrator checklist).
     *
     * Both count rows in `ai_runs` rather than a cache counter, so restarting the cache, the
     * queue or the server cannot hand anyone a fresh allowance.
     */
    private function budgetRefusal(Workspace $workspace, User $user, AiSetting $settings): ?string
    {
        $hourly = self::ceiling(config('ai.limits.max_runs_per_user_per_hour'));

        if ($hourly !== null) {
            $used = AiRun::withoutWorkspaceScope()
                ->where('user_id', $user->getKey())
                ->where('created_at', '>=', Carbon::now()->subHour())
                ->count();

            if ($used >= $hourly) {
                return __('ai.gate.rate_limited', ['limit' => $hourly]);
            }
        }

        $daily = self::ceiling($settings->max_runs_per_day);

        if ($daily !== null) {
            $used = AiRun::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->getKey())
                ->where('created_at', '>=', Carbon::now()->startOfDay())
                ->count();

            if ($used >= $daily) {
                return __('ai.gate.daily_cap', ['limit' => $daily]);
            }
        }

        return null;
    }

    /**
     * A usable ceiling, or null for "no ceiling". Zero, negatives and non-numeric settings all
     * mean unset rather than "no run may ever start" — a mistyped env var must not silently
     * disable the AI layer.
     */
    private static function ceiling(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
