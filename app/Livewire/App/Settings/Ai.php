<?php

declare(strict_types=1);

namespace App\Livewire\App\Settings;

use App\Ai\Agent\ToolRegistry;
use App\Ai\Contracts\AiTool;
use App\Enums\AiMode;
use App\Enums\AiToolRisk;
use App\Models\AiPolicy;
use App\Models\AiProvider;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * What the agent is allowed to do in this workspace, and on whose say-so.
 *
 * The mode is the decision that matters, so it is presented as three cards that state the
 * consequence rather than as a select box: assistant cannot change anything, copilot asks
 * before every change, autonomous acts and reports. Somebody choosing here is deciding how
 * much authority to hand over, and a dropdown does not read like that decision.
 *
 * Two rows are written from this screen. `ai_settings` holds the mode, the limits and the
 * kill switch; `ai_policies` holds the tool allow-list and the tools that always need a
 * human. A workspace inherits the install-wide defaults until it saves, at which point it
 * gets rows of its own — which is why nothing here edits a row whose `workspace_id` is null
 * (that one belongs to `/admin`, and the policies refuse it anyway).
 *
 * The kill switch is deliberately the loudest control on the page. It stops new runs and
 * aborts queued ones at their next step (ARCHITECTURE.md §7.7); it is the thing somebody
 * reaches for when the agent is doing something they did not expect.
 */
final class Ai extends Component
{
    private const POLICY_NAME = 'Workspace policy';

    public Workspace $workspace;

    public bool $isEnabled = false;

    public ?int $providerId = null;

    public string $defaultMode = 'assistant';

    public bool $autonomousEnabled = false;

    public bool $notifyOnAction = true;

    public string $systemInstructions = '';

    public int $maxToolCallsPerRun = 25;

    public int $maxRunSeconds = 180;

    public int $maxRunsPerDay = 500;

    public int $errorThreshold = 3;

    public ?int $retentionDays = null;

    /** Tools the agent may use at all. Empty means "everything not denied". */
    public array $allowedTools = [];

    /** Tools that always stop for a human, whatever the mode says. */
    public array $approvalTools = [];

    public bool $restrictTools = false;

    public bool $showKillSwitch = false;

    public string $killSwitchReason = '';

    public function mount(Workspace $workspace): void
    {
        $this->authorize('viewAny', [AiSetting::class, $workspace]);

        $this->workspace = $workspace;

        $setting = AiSetting::forWorkspace($workspace);

        if ($setting !== null) {
            $this->isEnabled = (bool) $setting->is_enabled;
            $this->providerId = $setting->ai_provider_id === null ? null : (int) $setting->ai_provider_id;
            $this->defaultMode = ($setting->default_mode ?? AiMode::Assistant)->value;
            $this->autonomousEnabled = (bool) $setting->autonomous_enabled;
            $this->notifyOnAction = (bool) $setting->notify_on_action;
            $this->systemInstructions = (string) $setting->system_instructions;
            $this->maxToolCallsPerRun = (int) $setting->max_tool_calls_per_run;
            $this->maxRunSeconds = (int) $setting->max_run_seconds;
            $this->maxRunsPerDay = (int) $setting->max_runs_per_day;
            $this->errorThreshold = (int) $setting->error_threshold;
            $this->retentionDays = $setting->retention_days === null ? null : (int) $setting->retention_days;
        }

        $policy = $this->policy();

        if ($policy !== null) {
            $allowed = is_array($policy->allowed_tools) ? $policy->allowed_tools : [];

            $this->restrictTools = $allowed !== [];
            $this->allowedTools = array_values(array_map(strval(...), $allowed));
            $this->approvalTools = array_values(array_map(
                strval(...),
                is_array($policy->approval_required_tools) ? $policy->approval_required_tools : [],
            ));
        }
    }

    /* ------------------------------------------------------------------ *
     * Reads
     * ------------------------------------------------------------------ */

    /**
     * The row governing this workspace — its own if it has one, otherwise the install-wide
     * default it is currently inheriting.
     */
    #[Computed]
    public function setting(): ?AiSetting
    {
        return AiSetting::forWorkspace($this->workspace);
    }

    public function inheritsDefaults(): bool
    {
        $setting = $this->setting;

        return $setting === null || $setting->workspace_id === null;
    }

    /**
     * @return Collection<int, AiProvider>
     */
    #[Computed]
    public function providers(): Collection
    {
        if (! Gate::allows('viewAny', AiProvider::class)) {
            return collect();
        }

        // `api_key` is hidden on the model and never read here: this screen only needs to
        // say which endpoint a workspace is pointed at.
        return AiProvider::query()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'driver', 'model', 'is_active', 'is_default']);
    }

    /**
     * The whole catalogue, grouped, so the allow-list is a list of capabilities rather than
     * of class names.
     *
     * `AiTool::description()` is written for the model and is what the prompt carries. The
     * person reading this screen is deciding what the agent may reach for, and is not
     * necessarily reading English — so the group heading and the sentence under each tool
     * come from `lang/<locale>/ai.php`, and fall back to the tool's own words for anything
     * not catalogued yet. The snake_case name is never translated: it is the string a policy
     * allow-list and an `ai_tool_runs` row are addressed by.
     *
     * @return array<string, list<array{name: string, description: string, risk: AiToolRisk, mutating: bool}>>
     */
    #[Computed]
    public function toolCatalogue(): array
    {
        $grouped = [];

        foreach (app(ToolRegistry::class)->all() as $tool) {
            /** @var AiTool $tool */
            $grouped[$this->translated('groups', $tool->group(), $tool->group())][] = [
                'name' => $tool->name(),
                'description' => $this->translated('about', $tool->name(), $tool->description()),
                'risk' => $tool->risk(),
                'mutating' => $tool->isMutating(),
            ];
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * A catalogued line for this tool or group, or the fallback the code already had.
     */
    private function translated(string $bucket, string $key, string $fallback): string
    {
        $line = 'ai.tools.'.$bucket.'.'.$key;

        return Lang::has($line) ? (string) __($line) : $fallback;
    }

    /**
     * @return list<string>
     */
    public function toolNames(): array
    {
        return app(ToolRegistry::class)->names();
    }

    /**
     * Whether the platform master switch is open at all. When it is not, nothing on this
     * page can make the agent run, and saying so beats letting somebody configure a thing
     * that will never start.
     */
    public function platformEnabled(): bool
    {
        return (bool) config('ai.enabled');
    }

    public function killSwitchEngaged(): bool
    {
        return $this->setting?->kill_switch_engaged === true;
    }

    public function canManage(): bool
    {
        return Gate::allows('ai.manage', $this->workspace);
    }

    public function canGrantAutonomy(): bool
    {
        return Gate::allows('ai.autonomous', $this->workspace);
    }

    /**
     * @return array<string, array{title: string, summary: string, consequences: list<string>, color: string}>
     */
    public function modeCards(): array
    {
        return [
            AiMode::Assistant->value => [
                'title' => AiMode::Assistant->label(),
                'summary' => __('Answers and drafts. It cannot change anything.'),
                'consequences' => [
                    __('Reads projects, tasks, time and documents you can already see.'),
                    __('Writes summaries, drafts and suggestions into the conversation.'),
                    __('No task is created, moved, assigned or deleted. Ever.'),
                ],
                'color' => AiMode::Assistant->color(),
            ],
            AiMode::Copilot->value => [
                'title' => AiMode::Copilot->label(),
                'summary' => __('Proposes a change and waits for you to approve it.'),
                'consequences' => [
                    __('Every change is shown before it happens, with what it will touch.'),
                    __('Nothing is written until somebody with the right to do it approves.'),
                    __('Approvals and refusals are recorded against the run.'),
                ],
                'color' => AiMode::Copilot->color(),
            ],
            AiMode::Autonomous->value => [
                'title' => AiMode::Autonomous->label(),
                'summary' => __('Acts on its own, inside the limits below, and tells you afterwards.'),
                'consequences' => [
                    __('Carries out permitted, non-approval tools without asking first.'),
                    __('Still bound by the acting person’s permissions — it can never exceed them.'),
                    __('High-risk and destructive tools still stop for a human.'),
                    __('Every action is written to the audit trail with the run that caused it.'),
                ],
                'color' => AiMode::Autonomous->color(),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Writes
     * ------------------------------------------------------------------ */

    public function selectMode(string $mode): void
    {
        $case = AiMode::tryFrom($mode);

        if ($case === null) {
            return;
        }

        if ($case === AiMode::Autonomous && ! $this->canGrantAutonomy()) {
            $this->dispatch('planvio-notify', type: 'error', message: __('Autonomous mode is granted by an owner or administrator.'));

            return;
        }

        $this->defaultMode = $case->value;

        // Choosing autonomous as the default without switching autonomy on would silently
        // degrade to copilot on the first run, which is a setting that lies.
        if ($case === AiMode::Autonomous) {
            $this->autonomousEnabled = true;
        }
    }

    public function save(ActivityLogger $activity): void
    {
        $setting = $this->ownSetting();

        $this->authorizeWrite($setting);

        $data = $this->validate([
            'isEnabled' => ['boolean'],
            // `pluck` rather than `modelKeys`: when the acting user may not see providers at
            // all the list is an empty base collection, which has no model keys to ask for.
            'providerId' => ['nullable', 'integer', Rule::in($this->providers->pluck('id')->all())],
            'defaultMode' => ['required', Rule::in(array_column(AiMode::cases(), 'value'))],
            'autonomousEnabled' => ['boolean'],
            'notifyOnAction' => ['boolean'],
            'systemInstructions' => ['nullable', 'string', 'max:4000'],
            'maxToolCallsPerRun' => ['required', 'integer', 'between:1,200'],
            'maxRunSeconds' => ['required', 'integer', 'between:10,900'],
            'maxRunsPerDay' => ['required', 'integer', 'between:1,100000'],
            'errorThreshold' => ['required', 'integer', 'between:1,20'],
            'retentionDays' => ['nullable', 'integer', 'between:1,3650'],
        ], attributes: [
            'providerId' => __('provider'),
            'defaultMode' => __('mode'),
            'maxToolCallsPerRun' => __('tool calls per run'),
            'maxRunSeconds' => __('run time limit'),
            'maxRunsPerDay' => __('runs per day'),
            'errorThreshold' => __('error threshold'),
            'retentionDays' => __('retention'),
        ]);

        $mode = AiMode::from($data['defaultMode']);
        $autonomous = (bool) $data['autonomousEnabled'];

        if ($autonomous && ! $this->canGrantAutonomy()) {
            $autonomous = false;
            $mode = $mode === AiMode::Autonomous ? AiMode::Copilot : $mode;
        }

        $known = $this->toolNames();
        $allowed = $this->restrictTools
            ? array_values(array_intersect($this->allowedTools, $known))
            : [];
        $approval = array_values(array_intersect($this->approvalTools, $known));

        if ($this->restrictTools && $allowed === []) {
            $this->addError('allowedTools', __('An allow-list with nothing on it would switch every tool off. Choose at least one, or turn the restriction off.'));

            return;
        }

        $actor = $this->actor();

        DB::transaction(function () use ($setting, $data, $mode, $autonomous, $allowed, $approval, $activity, $actor): void {
            $setting->fill([
                'workspace_id' => $this->workspace->getKey(),
                'is_enabled' => (bool) $data['isEnabled'],
                'ai_provider_id' => $data['providerId'],
                'default_mode' => $mode,
                'autonomous_enabled' => $autonomous,
                'notify_on_action' => (bool) $data['notifyOnAction'],
                'system_instructions' => $data['systemInstructions'] === '' ? null : $data['systemInstructions'],
                'max_tool_calls_per_run' => (int) $data['maxToolCallsPerRun'],
                'max_run_seconds' => (int) $data['maxRunSeconds'],
                'max_runs_per_day' => (int) $data['maxRunsPerDay'],
                'error_threshold' => (int) $data['errorThreshold'],
                'retention_days' => $data['retentionDays'],
            ]);

            $setting->save();

            $policy = $this->policy();

            // The tool lists are a separate authority from the settings row, so they carry
            // their own check rather than riding on the one above.
            if ($policy instanceof AiPolicy) {
                $this->authorize('update', $policy);
            } else {
                $this->authorize('create', [AiPolicy::class, $this->workspace]);

                $policy = new AiPolicy([
                    'workspace_id' => $this->workspace->getKey(),
                    'name' => self::POLICY_NAME,
                    'priority' => 0,
                ]);
            }

            $policy->workspace_id = $this->workspace->getKey();
            $policy->project_id = null;
            $policy->name = self::POLICY_NAME;
            $policy->allowed_tools = $allowed === [] ? null : $allowed;
            $policy->approval_required_tools = $approval === [] ? null : $approval;
            $policy->is_active = true;
            $policy->save();

            // Deliberately coarse: the mode and the autonomy flag are the facts worth an
            // audit line. The instructions are workspace content, not a security decision.
            $activity->forUser($actor)->log($setting, 'updated', [
                'is_enabled' => (bool) $setting->is_enabled,
                'default_mode' => $setting->default_mode->value,
                'autonomous_enabled' => (bool) $setting->autonomous_enabled,
                'allowed_tool_count' => count($allowed),
                'approval_tool_count' => count($approval),
            ]);
        });

        unset($this->setting);

        $this->dispatch('planvio-notify', type: 'success', message: __('AI settings saved.'));
    }

    public function engageKillSwitch(ActivityLogger $activity): void
    {
        $setting = $this->ownSetting();

        if ($setting->exists) {
            $this->authorize('engageKillSwitch', $setting);
        } else {
            // Stopping an agent a workspace has not configured yet still writes the row it
            // will be governed by, so it is a create.
            $this->authorize('create', [AiSetting::class, $this->workspace]);
        }

        $reason = trim($this->killSwitchReason);

        $setting->workspace_id = $this->workspace->getKey();
        $setting->kill_switch_engaged = true;
        $setting->kill_switch_reason = $reason === '' ? null : mb_substr($reason, 0, 255);
        $setting->kill_switch_at = now();
        $setting->save();

        $activity->forUser($this->actor())->log($setting, 'ai_kill_switch_engaged', [
            'reason' => $setting->kill_switch_reason,
        ]);

        $this->showKillSwitch = false;
        $this->killSwitchReason = '';

        unset($this->setting);

        $this->dispatch('planvio-notify', type: 'warning', message: __('AI is stopped. No new run will start and queued runs abort at their next step.'));
    }

    public function releaseKillSwitch(ActivityLogger $activity): void
    {
        $setting = $this->ownSetting();

        if (! $setting->exists) {
            return;
        }

        $this->authorize('releaseKillSwitch', $setting);

        $setting->kill_switch_engaged = false;
        $setting->kill_switch_reason = null;
        $setting->kill_switch_at = null;
        $setting->save();

        $activity->forUser($this->actor())->log($setting, 'ai_kill_switch_released');

        unset($this->setting);

        $this->dispatch('planvio-notify', type: 'success', message: __('AI is running again.'));
    }

    public function render(): View
    {
        return view('livewire.app.settings.ai');
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    /**
     * The workspace's own settings row, seeded from whatever it is currently inheriting.
     *
     * Never the install-wide row: that one belongs to `/admin`, and every policy method here
     * refuses a null `workspace_id` anyway.
     */
    private function ownSetting(): AiSetting
    {
        $own = AiSetting::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->first();

        if ($own instanceof AiSetting) {
            return $own;
        }

        $inherited = AiSetting::query()->whereNull('workspace_id')->first();

        return new AiSetting([
            'workspace_id' => $this->workspace->getKey(),
            'is_enabled' => (bool) ($inherited?->is_enabled ?? false),
            'ai_provider_id' => $inherited?->ai_provider_id,
            'default_mode' => $inherited?->default_mode ?? AiMode::Assistant,
            'max_tool_calls_per_run' => (int) ($inherited?->max_tool_calls_per_run ?? 25),
            'max_run_seconds' => (int) ($inherited?->max_run_seconds ?? 180),
            'max_runs_per_day' => (int) ($inherited?->max_runs_per_day ?? 500),
            'error_threshold' => (int) ($inherited?->error_threshold ?? 3),
            'notify_on_action' => (bool) ($inherited?->notify_on_action ?? true),
        ]);
    }

    private function authorizeWrite(AiSetting $setting): void
    {
        if ($setting->exists) {
            $this->authorize('update', $setting);

            return;
        }

        $this->authorize('create', [AiSetting::class, $this->workspace]);
    }

    private function policy(): ?AiPolicy
    {
        return AiPolicy::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->whereNull('project_id')
            ->where('name', self::POLICY_NAME)
            ->first();
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
