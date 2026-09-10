<?php

declare(strict_types=1);

namespace App\Ai\Policy;

use App\Ai\Agent\RunLimits;
use App\Ai\Contracts\AiTool;
use App\Enums\AiMode;
use App\Enums\AiToolRisk;
use App\Enums\WorkspaceRole;
use App\Models\AiPolicy;
use App\Models\AiSetting;

/**
 * The whole configuration stack folded into one immutable answer for a single run.
 *
 * Resolution is *narrowing only*, in both directions of the hierarchy described in
 * ARCHITECTURE.md §7 and AI_SECURITY.md: `config/ai.php` bounds the workspace, the workspace
 * bounds the project, and the acting user's own authority bounds all of it. Every fold below
 * therefore takes the more restrictive side —
 *
 *   mode                  the lower of the workspace mode and any policy mode
 *   denied_tools          union: any rule that denies a tool denies it for the run
 *   allowed_tools         intersection: a rule that names an allow-list can only shrink one
 *   approval_required     union: any rule demanding approval gets it
 *   max_risk              minimum level across the stack
 *   allowed_roles         intersection
 *   limits                {@see RunLimits} clamps every workspace number at the config ceiling
 *
 * — so no ordering of rows can widen what a higher tier permitted. That is why the object is
 * safe to compute once, at the start of a run, and carry on `AgentContext`.
 *
 * `alwaysRequireApproval` comes from `config('ai.approvals.always_require_approval')` and is
 * deliberately not foldable: those four tools require a human every time and no workspace
 * policy can waive them (AI_SECURITY.md, "Approval gating").
 *
 * This object decides *what the rules say*. Whether a specific call proceeds is the runner's
 * gate: registry resolution, schema validation, `AgentContext::can()` and the workspace
 * assertion all still happen, and all of them can refuse after this one has allowed.
 */
final readonly class ResolvedPolicy
{
    /** `config('ai.enabled')` is false: the AI layer is off for the whole installation. */
    public const BLOCKED_MASTER_SWITCH = 'master_switch';

    /** Neither the workspace nor the platform has an `ai_settings` row yet. */
    public const BLOCKED_UNCONFIGURED = 'unconfigured';

    /** The workspace has AI switched off. */
    public const BLOCKED_DISABLED = 'disabled';

    /** `ai_settings.kill_switch_engaged` — no new run starts, at any mode (§7.7). */
    public const BLOCKED_KILL_SWITCH = 'kill_switch';

    /** The acting user is not an active member of the bound workspace. */
    public const BLOCKED_NOT_A_MEMBER = 'not_a_member';

    /** A policy restricts the agent to workspace roles the acting user does not hold. */
    public const BLOCKED_ROLE = 'role_not_permitted';

    /**
     * @param list<string>|null $allowedTools null means "everything not denied"
     * @param list<string> $deniedTools
     * @param list<string> $approvalRequiredTools
     * @param list<WorkspaceRole>|null $allowedRoles null means "every role the permission matrix allows"
     * @param list<string> $alwaysRequireApproval never waivable
     * @param bool $enabled the master switch and the workspace switch are both open
     * @param bool $killSwitch `ai_settings.kill_switch_engaged`
     * @param bool $autonomousAllowed the workspace permits unattended execution at all
     * @param RunLimits|null $limits null means the platform ceilings; read it through {@see self::runLimits()}
     * @param string|null $blockedReason one of the BLOCKED_* constants; null when a run may start
     */
    public function __construct(
        public AiMode $mode,
        public AiToolRisk $maxRisk,
        public ?array $allowedTools = null,
        public array $deniedTools = [],
        public array $approvalRequiredTools = [],
        public ?array $allowedRoles = null,
        public array $alwaysRequireApproval = [],
        public bool $enabled = true,
        public bool $killSwitch = false,
        public bool $autonomousAllowed = false,
        public ?RunLimits $limits = null,
        public ?string $blockedReason = null,
    ) {}

    /* ------------------------------------------------------------------ *
     * Construction
     * ------------------------------------------------------------------ */

    /**
     * Fold $policies — expected in the order {@see AiPolicy::scopeApplicableTo()} returns —
     * over the workspace settings.
     *
     * The order does not actually matter to the result, because every fold is commutative
     * and narrowing. That is a property worth keeping: it means a new rule can never widen
     * an existing one by sorting ahead of it.
     *
     * This is the settings-and-rules half only. {@see PolicyResolver} is the entry point that
     * also applies the master switch, the kill switch and the acting user's own authority.
     *
     * @param iterable<AiPolicy> $policies
     */
    public static function resolve(AiSetting $settings, iterable $policies = []): self
    {
        $mode = $settings->effectiveMode();

        /** @var list<string>|null $allowed */
        $allowed = null;
        /** @var list<WorkspaceRole>|null $roles */
        $roles = null;
        $denied = [];
        $approval = [];
        $maxRisk = null;

        foreach ($policies as $policy) {
            if (! $policy->is_active) {
                continue;
            }

            if ($policy->mode instanceof AiMode) {
                $mode = self::narrowerMode($mode, $policy->mode);
            }

            $denied = array_merge($denied, self::toolNames($policy->denied_tools));
            $approval = array_merge($approval, self::toolNames($policy->approval_required_tools));

            if (is_array($policy->allowed_tools)) {
                $list = self::toolNames($policy->allowed_tools);

                $allowed = $allowed === null
                    ? $list
                    : array_values(array_intersect($allowed, $list));
            }

            if ($policy->max_risk instanceof AiToolRisk) {
                $maxRisk = $maxRisk === null || $policy->max_risk->level() < $maxRisk->level()
                    ? $policy->max_risk
                    : $maxRisk;
            }

            if (is_array($policy->allowed_roles)) {
                $list = self::roles($policy->allowed_roles);

                $roles = $roles === null
                    ? $list
                    : array_values(array_uintersect(
                        $roles,
                        $list,
                        static fn (WorkspaceRole $a, WorkspaceRole $b): int => strcmp($a->value, $b->value),
                    ));
            }
        }

        return new self(
            mode: $mode,
            // The column defaults to `medium`; a stack with no explicit ceiling inherits it.
            maxRisk: $maxRisk ?? AiToolRisk::Medium,
            allowedTools: $allowed,
            deniedTools: array_values(array_unique($denied)),
            approvalRequiredTools: array_values(array_unique($approval)),
            allowedRoles: $roles,
            alwaysRequireApproval: self::alwaysRequireApprovalFromConfig(),
            enabled: (bool) config('ai.enabled') && $settings->is_enabled,
            killSwitch: (bool) $settings->kill_switch_engaged,
            autonomousAllowed: $settings->autonomousAllowed(),
            limits: RunLimits::fromSettings($settings),
        );
    }

    /**
     * A fully closed policy: no tool allowed, nothing above read risk, assistant mode, and a
     * stated reason. Every refusal in {@see PolicyResolver} returns one of these rather than a
     * partially open object, so a caller that ignores `blockedReason` still cannot run
     * anything.
     */
    public static function blocked(string $reason, ?AiSetting $settings = null): self
    {
        return new self(
            mode: AiMode::Assistant,
            maxRisk: AiToolRisk::Read,
            allowedTools: [],
            deniedTools: [],
            approvalRequiredTools: [],
            allowedRoles: null,
            alwaysRequireApproval: self::alwaysRequireApprovalFromConfig(),
            enabled: (bool) config('ai.enabled') && $settings?->is_enabled === true,
            killSwitch: $settings?->kill_switch_engaged === true,
            autonomousAllowed: false,
            limits: $settings === null ? RunLimits::ceilings() : RunLimits::fromSettings($settings),
            blockedReason: $reason,
        );
    }

    /**
     * A copy in a narrower mode. Widening is refused silently — the caller keeps the mode it
     * already had — because a mode change is a settings decision, not a run-time one.
     */
    public function withMode(AiMode $mode): self
    {
        $narrower = self::narrowerMode($this->mode, $mode);

        if ($narrower === $this->mode) {
            return $this;
        }

        return new self(
            mode: $narrower,
            maxRisk: $this->maxRisk,
            allowedTools: $this->allowedTools,
            deniedTools: $this->deniedTools,
            approvalRequiredTools: $this->approvalRequiredTools,
            allowedRoles: $this->allowedRoles,
            alwaysRequireApproval: $this->alwaysRequireApproval,
            enabled: $this->enabled,
            killSwitch: $this->killSwitch,
            autonomousAllowed: $this->autonomousAllowed,
            limits: $this->limits,
            blockedReason: $this->blockedReason,
        );
    }

    /* ------------------------------------------------------------------ *
     * Questions the runner asks
     * ------------------------------------------------------------------ */

    /**
     * Whether a run may start at all: the platform switch, the workspace switch, the kill
     * switch and the acting user's membership, in that order of precedence.
     */
    public function canStartRun(): bool
    {
        return $this->blockedReason === null && $this->enabled && ! $this->killSwitch;
    }

    /**
     * Whether this run may use $tool.
     *
     * An explicit deny always wins over an allow-list, and an allow-list denies everything
     * absent from it. Handed an {@see AiTool} rather than a bare name, it also refuses a
     * mutating tool in a mode that cannot mutate — assistant mode does not merely decline to
     * use them, it does not hold them (ARCHITECTURE.md §7.4, AI_SECURITY.md "Approval
     * gating").
     */
    public function allows(AiTool|string $tool): bool
    {
        if ($tool instanceof AiTool && $tool->isMutating() && ! $this->mode->canMutate()) {
            return false;
        }

        return $this->allowsTool($tool instanceof AiTool ? $tool->name() : $tool);
    }

    /**
     * The allow/deny half of {@see self::allows()}, by name. Mode is not considered here:
     * `ToolRegistry::forContext()` applies it separately while assembling the tool set.
     */
    public function allowsTool(string $tool): bool
    {
        if (in_array($tool, $this->deniedTools, true)) {
            return false;
        }

        return $this->allowedTools === null || in_array($tool, $this->allowedTools, true);
    }

    /**
     * Whether this call needs a human before it executes.
     *
     * Order matters: the unwaivable list is checked before anything a workspace configured,
     * assistant mode is refused any mutation outright, and a mode with no auto-execute
     * ceiling requires approval for everything.
     *
     * A bare tool name with no risk fails closed and is treated as destructive — the risk is
     * a property of the tool, so its absence is a caller bug, not a licence.
     */
    public function requiresApproval(AiTool|string $tool, ?AiToolRisk $risk = null): bool
    {
        $name = $tool instanceof AiTool ? $tool->name() : $tool;
        $risk = $tool instanceof AiTool ? $tool->risk() : ($risk ?? AiToolRisk::Destructive);

        if (in_array($name, $this->alwaysRequireApproval, true)) {
            return true;
        }

        if (in_array($name, $this->approvalRequiredTools, true)) {
            return true;
        }

        // Assistant mode executes no mutation, approved or otherwise. Saying so here as well
        // as in the registry means a tool reached by any other path still stops.
        if ($tool instanceof AiTool && $tool->isMutating() && ! $this->mode->canMutate()) {
            return true;
        }

        if ($risk->exceeds($this->maxRisk)) {
            return true;
        }

        $ceiling = $this->autoExecuteMaxRisk();

        if ($ceiling === null) {
            return true;
        }

        return $risk->exceeds($ceiling);
    }

    /**
     * The highest risk that runs unattended: the mode's ceiling from
     * `config('ai.approvals.auto_execute_max_risk')`, further capped by the stack's
     * `max_risk`. Null means nothing executes without approval.
     */
    public function autoExecuteMaxRisk(): ?AiToolRisk
    {
        $configured = config('ai.approvals.auto_execute_max_risk.'.$this->mode->value);

        $ceiling = is_string($configured) ? AiToolRisk::tryFrom($configured) : null;

        if ($ceiling === null) {
            return null;
        }

        return $ceiling->level() <= $this->maxRisk->level() ? $ceiling : $this->maxRisk;
    }

    /**
     * Whether a member holding $role may drive the agent under this policy. A null
     * `allowed_roles` does not grant anything — the permission matrix still decides — it
     * only declines to narrow further.
     */
    public function permitsRole(WorkspaceRole $role): bool
    {
        if ($this->allowedRoles === null) {
            return true;
        }

        return in_array($role, $this->allowedRoles, true);
    }

    /**
     * Whether the tool may execute without a human, all rules considered.
     */
    public function canAutoExecute(AiTool|string $tool, ?AiToolRisk $risk = null): bool
    {
        return $this->canStartRun()
            && $this->allows($tool)
            && ! $this->requiresApproval($tool, $risk);
    }

    /**
     * The execution ceilings for this run. Never wider than `config('ai.limits')`.
     */
    public function runLimits(): RunLimits
    {
        return $this->limits ?? RunLimits::ceilings();
    }

    /* ------------------------------------------------------------------ *
     * Explanation
     * ------------------------------------------------------------------ */

    /**
     * Why something was blocked, in a sentence a person can act on.
     *
     * Called with no argument it explains the run as a whole; called with a tool it explains
     * that tool's standing. It never names a record, a policy row or a configuration path the
     * reader may not be entitled to see — a refusal states that the boundary held, not what
     * lies on the other side.
     */
    public function reason(AiTool|string|null $tool = null, ?AiToolRisk $risk = null): string
    {
        $blocked = $this->runLevelReason();

        if ($blocked !== null) {
            return $blocked;
        }

        if ($tool === null) {
            return $this->modeReason();
        }

        $name = $tool instanceof AiTool ? $tool->name() : $tool;

        if (in_array($name, $this->deniedTools, true)) {
            return __(':tool is on this workspace\'s denied list, so the assistant cannot use it.', ['tool' => $name]);
        }

        if ($tool instanceof AiTool && $tool->isMutating() && ! $this->mode->canMutate()) {
            return __('The assistant is in :mode mode: it answers and advises but never changes records, so it cannot run :tool.', [
                'mode' => $this->mode->label(),
                'tool' => $name,
            ]);
        }

        if (! $this->allowsTool($name)) {
            return __('This workspace allows the assistant only a named set of tools, and :tool is not one of them.', [
                'tool' => $name,
            ]);
        }

        if (in_array($name, $this->alwaysRequireApproval, true)) {
            return __(':tool always needs a person to approve it, in every mode, and no policy can waive that.', [
                'tool' => $name,
            ]);
        }

        if ($this->requiresApproval($tool, $risk)) {
            return __(':tool needs a person to approve it before it runs.', ['tool' => $name]);
        }

        return __(':tool may run without approval in :mode mode.', [
            'tool' => $name,
            'mode' => $this->mode->label(),
        ]);
    }

    /**
     * @return array{
     *     mode: string,
     *     max_risk: string,
     *     auto_execute_max_risk: string|null,
     *     allowed_tools: list<string>|null,
     *     denied_tools: list<string>,
     *     approval_required_tools: list<string>,
     *     allowed_roles: list<string>|null,
     *     always_require_approval: list<string>,
     *     enabled: bool,
     *     kill_switch: bool,
     *     autonomous_allowed: bool,
     *     can_start_run: bool,
     *     blocked_reason: string|null,
     *     limits: array{
     *         max_tool_calls: int,
     *         max_seconds: int,
     *         max_errors: int,
     *         max_same_tool_repeats: int,
     *         max_context_tokens: int
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'max_risk' => $this->maxRisk->value,
            'auto_execute_max_risk' => $this->autoExecuteMaxRisk()?->value,
            'allowed_tools' => $this->allowedTools,
            'denied_tools' => $this->deniedTools,
            'approval_required_tools' => $this->approvalRequiredTools,
            'allowed_roles' => $this->allowedRoles === null
                ? null
                : array_map(static fn (WorkspaceRole $role): string => $role->value, $this->allowedRoles),
            'always_require_approval' => $this->alwaysRequireApproval,
            'enabled' => $this->enabled,
            'kill_switch' => $this->killSwitch,
            'autonomous_allowed' => $this->autonomousAllowed,
            'can_start_run' => $this->canStartRun(),
            'blocked_reason' => $this->blockedReason,
            'limits' => $this->runLimits()->toArray(),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function runLevelReason(): ?string
    {
        return match (true) {
            $this->blockedReason === self::BLOCKED_MASTER_SWITCH => __('AI is switched off for this installation.'),
            $this->blockedReason === self::BLOCKED_UNCONFIGURED => __('AI has not been configured for this workspace yet.'),
            $this->blockedReason === self::BLOCKED_DISABLED || ! $this->enabled => __('AI is switched off for this workspace.'),
            $this->blockedReason === self::BLOCKED_KILL_SWITCH || $this->killSwitch => __('The AI kill switch is engaged for this workspace, so no new run can start.'),
            $this->blockedReason === self::BLOCKED_NOT_A_MEMBER => __('You are not a member of this workspace.'),
            $this->blockedReason === self::BLOCKED_ROLE => __('A policy in this workspace limits the assistant to other roles.'),
            $this->blockedReason !== null => __('A policy in this workspace prevents the assistant from running.'),
            default => null,
        };
    }

    private function modeReason(): string
    {
        $ceiling = $this->autoExecuteMaxRisk();

        if ($ceiling === null) {
            return __('The assistant is in :mode mode: it answers and advises, and every change is left for a person to make.', [
                'mode' => $this->mode->label(),
            ]);
        }

        return __('The assistant is in :mode mode: it acts unattended up to :risk risk and asks for approval above it.', [
            'mode' => $this->mode->label(),
            'risk' => mb_strtolower($ceiling->label()),
        ]);
    }

    private static function narrowerMode(AiMode $a, AiMode $b): AiMode
    {
        return self::modeRank($a) <= self::modeRank($b) ? $a : $b;
    }

    private static function modeRank(AiMode $mode): int
    {
        return match ($mode) {
            AiMode::Assistant => 0,
            AiMode::Copilot => 1,
            AiMode::Autonomous => 2,
        };
    }

    /**
     * Tool names as the registry knows them: non-empty snake_case strings, de-duplicated.
     * Anything else in the JSON column is dropped rather than carried into a comparison the
     * registry would never match.
     *
     * @return list<string>
     */
    private static function toolNames(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $names = [];

        foreach ($value as $name) {
            if (! is_string($name)) {
                continue;
            }

            $name = mb_strtolower(trim($name));

            if ($name !== '' && preg_match('/\A[a-z0-9_]+\z/', $name) === 1) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return list<WorkspaceRole>
     */
    private static function roles(array $value): array
    {
        $roles = [];

        foreach ($value as $role) {
            if ($role instanceof WorkspaceRole) {
                $roles[] = $role;

                continue;
            }

            if (is_string($role)) {
                $resolved = WorkspaceRole::tryFrom($role);

                if ($resolved !== null) {
                    $roles[] = $resolved;
                }
            }
        }

        return array_values(array_unique($roles, SORT_REGULAR));
    }

    /**
     * @return list<string>
     */
    private static function alwaysRequireApprovalFromConfig(): array
    {
        return self::toolNames(config('ai.approvals.always_require_approval'));
    }
}
