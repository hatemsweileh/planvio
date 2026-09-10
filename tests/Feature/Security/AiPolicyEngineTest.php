<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolRegistry;
use App\Ai\Approvals\ApprovalService;
use App\Ai\Approvals\ResumesApprovedRuns;
use App\Ai\Contracts\AiTool;
use App\Ai\Policy\PolicyResolver;
use App\Ai\Policy\ResolvedPolicy;
use App\Ai\Tools\Elevated\ArchiveProjectTool;
use App\Ai\Tools\Elevated\DeleteProjectTool;
use App\Ai\Tools\Elevated\DeleteTaskTool;
use App\Ai\Tools\Elevated\ElevatedTool;
use App\Ai\Tools\Elevated\ManageProjectMemberTool;
use App\Ai\Tools\Elevated\RemoveWorkspaceMemberTool;
use App\Ai\Tools\Elevated\UpdateProjectSettingsTool;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiToolRisk;
use App\Enums\AiTrigger;
use App\Enums\ProjectRole;
use App\Enums\ToolRunStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiPolicy;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Ai\AiApprovalRequired;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The policy engine and the approval flow — what the AI may do, and who has to say yes.
 *
 * Two promises are under test here, and both are the kind that only mean something if
 * something actively tries to break them.
 *
 * **Narrowing only.** ARCHITECTURE.md §7 and AI_SECURITY.md describe a stack —
 * `config/ai.php`, then `ai_settings`, then `ai_policies`, then the acting user — in which
 * the most specific rule wins *as long as it takes something away*. So every precedence test
 * below writes the most permissive configuration the product can express at the lower tier
 * and asserts it changed nothing: a workspace that switches AI on while the master switch is
 * off, a policy raising `max_risk` to destructive inside a copilot workspace, a settings row
 * asking for twenty times the configured tool-call budget.
 *
 * **A human in the loop where it matters.** Assistant mode is not told to avoid mutations, it
 * is not given any. Copilot confirms every change and no read. Autonomous runs to the
 * configured ceiling and stops above it. The four unwaivable tools stop in every mode under
 * every policy. And an approval, once recorded, is granted once — approving twice executes
 * once, and an approval granted while the kill switch is engaged does not restart anything.
 */
final class AiPolicyEngineTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ *
     * Modes
     * ------------------------------------------------------------------ */

    public function test_assistant_mode_holds_no_mutating_tools_at_all(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $this->settingsFor($workspace, AiMode::Assistant);

        $policy = $this->resolve($workspace, $owner);

        $this->assertSame(AiMode::Assistant, $policy->mode);
        $this->assertTrue($policy->canStartRun());

        // Not "is told not to use them": is not given them.
        $offered = app(ToolRegistry::class)->forContext($this->contextFor($owner, $workspace, $policy));

        $this->assertNotEmpty($offered, 'Assistant mode was offered no tools at all, not even reads.');

        foreach ($offered as $tool) {
            $this->assertFalse(
                $tool->isMutating(),
                "{$tool->name()} was offered to the model in assistant mode.",
            );
        }

        foreach ($this->mutatingTools() as $tool) {
            $this->assertFalse($policy->allows($tool), "{$tool->name()} is available in assistant mode.");
            $this->assertTrue($policy->requiresApproval($tool), "{$tool->name()} may mutate in assistant mode.");
            $this->assertFalse($policy->canAutoExecute($tool), "{$tool->name()} may auto-execute in assistant mode.");
        }
    }

    public function test_copilot_mode_requires_approval_for_every_mutation_but_not_for_reads(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $this->settingsFor($workspace, AiMode::Copilot);

        $policy = $this->resolve($workspace, $owner);

        $this->assertSame(AiMode::Copilot, $policy->mode);
        $this->assertSame(AiToolRisk::Read, $policy->autoExecuteMaxRisk());

        foreach ($this->allTools() as $tool) {
            $this->assertTrue($policy->allows($tool), "Copilot mode withheld {$tool->name()} entirely.");

            // A mutation that declared itself read-risk would slip through the ceiling below.
            $this->assertFalse(
                $tool->isMutating() && $tool->risk() === AiToolRisk::Read,
                "{$tool->name()} changes data but declares read-only risk.",
            );

            // Copilot's ceiling is `read`: anything above it is confirmed, whether it mutates
            // or merely costs something (generate_project_report is low risk and writes nothing).
            $this->assertSame(
                $tool->risk()->exceeds(AiToolRisk::Read),
                $policy->requiresApproval($tool),
                "{$tool->name()} ({$tool->risk()->value}) was gated the wrong way in copilot mode.",
            );

            if ($tool->isMutating()) {
                $this->assertTrue(
                    $policy->requiresApproval($tool),
                    "{$tool->name()} changes data and was not sent for approval in copilot mode.",
                );
                $this->assertFalse($policy->canAutoExecute($tool));
            }

            if ($tool->risk() === AiToolRisk::Read) {
                $this->assertFalse(
                    $policy->requiresApproval($tool),
                    "{$tool->name()} only reads and was sent for approval in copilot mode.",
                );
                $this->assertTrue($policy->canAutoExecute($tool));
            }
        }
    }

    public function test_autonomous_mode_executes_up_to_medium_risk_and_asks_above_it(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $policy = $this->resolve($workspace, $owner);

        $this->assertSame(AiMode::Autonomous, $policy->mode);
        $this->assertSame(AiToolRisk::Medium, $policy->autoExecuteMaxRisk());

        foreach ($this->allTools() as $tool) {
            $unattended = ! $tool->risk()->exceeds(AiToolRisk::Medium)
                && ! in_array($tool->name(), ElevatedTool::UNWAIVABLE, true);

            $this->assertSame(
                ! $unattended,
                $policy->requiresApproval($tool),
                "{$tool->name()} ({$tool->risk()->value}) was gated the wrong way in autonomous mode.",
            );
        }

        foreach ($this->elevatedTools() as $tool) {
            $this->assertTrue(
                $policy->requiresApproval($tool),
                "{$tool->name()} ({$tool->risk()->value}) may run unattended in autonomous mode.",
            );
        }
    }

    /**
     * Autonomous execution is a grant a person holds, not a setting the workspace holds alone.
     */
    public function test_a_user_without_the_autonomous_permission_runs_as_copilot(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $member = $this->makeMember($workspace, WorkspaceRole::Member);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $this->assertSame(AiMode::Autonomous, $this->resolve($workspace, $owner)->mode);

        $degraded = $this->resolve($workspace, $member);

        $this->assertSame(AiMode::Copilot, $degraded->mode);
        $this->assertSame(AiToolRisk::Read, $degraded->autoExecuteMaxRisk());
    }

    public function test_a_non_member_gets_a_closed_policy(): void
    {
        $workspace = $this->makeWorkspace();
        $outsider = User::factory()->create();
        $this->settingsFor($workspace, AiMode::Autonomous);

        $policy = $this->resolve($workspace, $outsider);

        $this->assertFalse($policy->canStartRun());
        $this->assertSame(ResolvedPolicy::BLOCKED_NOT_A_MEMBER, $policy->blockedReason);
        $this->assertSame(AiMode::Assistant, $policy->mode);

        foreach ($this->allTools() as $tool) {
            $this->assertFalse($policy->allows($tool), "{$tool->name()} was offered to a non-member.");
        }
    }

    /* ------------------------------------------------------------------ *
     * The unwaivable four
     * ------------------------------------------------------------------ */

    public function test_the_four_always_approve_tools_require_approval_in_every_mode_and_under_every_policy(): void
    {
        foreach ([AiMode::Assistant, AiMode::Copilot, AiMode::Autonomous] as $mode) {
            // The most permissive world a workspace administrator can build: the mode's
            // auto-execute ceiling raised to destructive, and a policy that names the four in
            // `allowed_tools`, raises `max_risk` to destructive and demands approval for
            // nothing.
            config(['ai.approvals.auto_execute_max_risk.'.$mode->value => 'destructive']);

            $workspace = $this->makeWorkspace();
            $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
            $this->settingsFor($workspace, $mode);

            AiPolicy::factory()
                ->allowing(ElevatedTool::UNWAIVABLE)
                ->requiringApprovalFor([])
                ->maxRisk(AiToolRisk::Destructive)
                ->forMode(AiMode::Autonomous)
                ->create(['workspace_id' => $workspace->getKey()]);

            $policy = $this->resolve($workspace, $owner);

            foreach ($this->unwaivableTools() as $tool) {
                $this->assertTrue(
                    $policy->requiresApproval($tool),
                    "{$tool->name()} escaped the unwaivable approval gate in {$mode->value} mode.",
                );

                $this->assertFalse(
                    $policy->canAutoExecute($tool),
                    "{$tool->name()} may auto-execute in {$mode->value} mode.",
                );

                $this->assertStringContainsString(
                    $tool->name(),
                    $policy->reason($tool),
                    'The refusal does not name the tool it refused.',
                );
            }
        }
    }

    /* ------------------------------------------------------------------ *
     * Precedence: a lower tier may only narrow
     * ------------------------------------------------------------------ */

    public function test_a_workspace_cannot_widen_the_master_switch(): void
    {
        config(['ai.enabled' => false]);

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        // Everything below the master switch says yes as loudly as it can.
        $this->settingsFor($workspace, AiMode::Autonomous);

        AiPolicy::factory()
            ->maxRisk(AiToolRisk::Destructive)
            ->forMode(AiMode::Autonomous)
            ->create(['workspace_id' => $workspace->getKey()]);

        $policy = $this->resolve($workspace, $owner);

        $this->assertFalse($policy->enabled);
        $this->assertFalse($policy->canStartRun());
        $this->assertSame(ResolvedPolicy::BLOCKED_MASTER_SWITCH, $policy->blockedReason);
        $this->assertSame(AiMode::Assistant, $policy->mode);
        $this->assertSame(AiToolRisk::Read, $policy->maxRisk);

        foreach ($this->allTools() as $tool) {
            $this->assertFalse(
                $policy->allows($tool),
                "{$tool->name()} was available with config('ai.enabled') switched off.",
            );
        }
    }

    public function test_a_workspace_policy_cannot_widen_the_configured_mode_or_risk_ceiling(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $this->settingsFor($workspace, AiMode::Copilot);

        // A policy that asks for more than the workspace: a higher mode and a higher ceiling.
        AiPolicy::factory()
            ->maxRisk(AiToolRisk::Destructive)
            ->forMode(AiMode::Autonomous)
            ->create(['workspace_id' => $workspace->getKey()]);

        $policy = $this->resolve($workspace, $owner);

        $this->assertSame(AiMode::Copilot, $policy->mode, 'A policy raised the workspace mode.');
        $this->assertSame(
            AiToolRisk::Read,
            $policy->autoExecuteMaxRisk(),
            'A policy raised the auto-execute ceiling above the mode configured in config/ai.php.',
        );

        foreach ($this->mutatingTools() as $tool) {
            $this->assertTrue(
                $policy->requiresApproval($tool),
                "{$tool->name()} auto-executed in copilot mode because a policy raised max_risk.",
            );
        }
    }

    public function test_a_project_policy_can_only_narrow_the_workspace_policy(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);
        $this->settingsFor($workspace, AiMode::Autonomous);

        AiPolicy::factory()
            ->allowing(['create_task', 'update_task', 'create_comment'])
            ->maxRisk(AiToolRisk::Medium)
            ->create(['workspace_id' => $workspace->getKey()]);

        AiPolicy::factory()
            ->allowing(['create_task', 'update_task', 'delete_task', 'create_project'])
            ->denying(['update_task'])
            ->maxRisk(AiToolRisk::Low)
            ->forMode(AiMode::Copilot)
            ->forProject($project)
            ->create();

        $scoped = $this->resolve($workspace, $owner, $project);

        // Allow-lists intersect, denies win, and the lower mode and ceiling are the ones kept.
        $this->assertSame(AiMode::Copilot, $scoped->mode);
        $this->assertSame(AiToolRisk::Low, $scoped->maxRisk);
        $this->assertTrue($scoped->allowsTool('create_task'));
        $this->assertFalse($scoped->allowsTool('update_task'));
        $this->assertFalse($scoped->allowsTool('create_comment'));
        $this->assertFalse($scoped->allowsTool('delete_task'));

        // Outside the project, the workspace rule alone applies — the project rule did not
        // leak upwards.
        $unscoped = $this->resolve($workspace, $owner);

        $this->assertSame(AiMode::Autonomous, $unscoped->mode);
        $this->assertTrue($unscoped->allowsTool('update_task'));
    }

    public function test_a_workspace_cannot_raise_a_limit_above_the_configured_ceiling(): void
    {
        config([
            'ai.limits.max_tool_calls_per_run' => 5,
            'ai.limits.max_run_seconds' => 30,
            'ai.limits.max_errors_per_run' => 2,
            'ai.limits.max_same_tool_repeats' => 3,
            'ai.limits.max_context_tokens' => 1000,
        ]);

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        $this->settingsFor($workspace, AiMode::Autonomous, [
            'max_tool_calls_per_run' => 500,
            'max_run_seconds' => 9999,
            'error_threshold' => 99,
        ]);

        $limits = $this->resolve($workspace, $owner)->runLimits();

        $this->assertSame(5, $limits->maxToolCalls, 'A workspace bought itself more tool calls than config allows.');
        $this->assertSame(30, $limits->maxSeconds, 'A workspace bought itself more wall clock than config allows.');
        $this->assertSame(2, $limits->maxErrors, 'A workspace raised its error tolerance above config.');
        $this->assertSame(3, $limits->maxSameToolRepeats);
        $this->assertSame(1000, $limits->maxContextTokens);

        // Narrowing, on the other hand, is exactly what the column is for.
        $lower = $this->makeWorkspace();
        $lowerOwner = $this->makeMember($lower, WorkspaceRole::Owner);

        $this->settingsFor($lower, AiMode::Autonomous, [
            'max_tool_calls_per_run' => 2,
            'max_run_seconds' => 10,
            'error_threshold' => 1,
        ]);

        $narrowed = $this->resolve($lower, $lowerOwner)->runLimits();

        $this->assertSame(2, $narrowed->maxToolCalls);
        $this->assertSame(10, $narrowed->maxSeconds);
        $this->assertSame(1, $narrowed->maxErrors);
    }

    /* ------------------------------------------------------------------ *
     * The kill switch
     * ------------------------------------------------------------------ */

    public function test_the_kill_switch_blocks_every_new_run(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        $this->settingsFor($workspace, AiMode::Autonomous, [
            'kill_switch_engaged' => true,
            'kill_switch_reason' => 'Engaged during a test',
            'kill_switch_at' => Carbon::now(),
        ]);

        // The most permissive policy in the product cannot reopen it.
        AiPolicy::factory()
            ->maxRisk(AiToolRisk::Destructive)
            ->forMode(AiMode::Autonomous)
            ->create(['workspace_id' => $workspace->getKey()]);

        $policy = $this->resolve($workspace, $owner);

        $this->assertTrue($policy->killSwitch);
        $this->assertFalse($policy->canStartRun(), 'A run may start with the kill switch engaged.');
        $this->assertSame(ResolvedPolicy::BLOCKED_KILL_SWITCH, $policy->blockedReason);
        $this->assertSame(AiMode::Assistant, $policy->mode);
        $this->assertStringContainsString('kill switch', mb_strtolower($policy->reason()));

        foreach ($this->allTools() as $tool) {
            $this->assertFalse($policy->allows($tool), "{$tool->name()} survived the kill switch.");
            $this->assertFalse($policy->canAutoExecute($tool), "{$tool->name()} may auto-execute past the kill switch.");
        }

        // And the model is handed nothing at all.
        $this->assertSame(
            [],
            app(ToolRegistry::class)->forContext($this->contextFor($owner, $workspace, $policy)),
        );
    }

    public function test_a_disabled_workspace_blocks_every_new_run(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        AiSetting::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'is_enabled' => false,
            'default_mode' => AiMode::Autonomous,
        ]);

        $policy = $this->resolve($workspace, $owner);

        $this->assertFalse($policy->enabled);
        $this->assertFalse($policy->canStartRun());
        $this->assertSame(ResolvedPolicy::BLOCKED_DISABLED, $policy->blockedReason);
    }

    /* ------------------------------------------------------------------ *
     * The approval flow
     * ------------------------------------------------------------------ */

    public function test_an_approval_request_parks_the_run_and_reaches_only_the_people_who_may_decide(): void
    {
        Notification::fake();

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $admin = $this->makeMember($workspace, WorkspaceRole::Admin);
        $projectManager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $otherManager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $member = $this->makeMember($workspace, WorkspaceRole::Member);

        $project = $this->makeProject($workspace, [[$projectManager, ProjectRole::Manager]]);
        $run = $this->runFor($workspace, $member, $project);
        $toolRun = $this->pendingToolRun($run);

        app(ApprovalService::class)->request($toolRun, [
            'project' => 'Website Redesign',
            'tasks' => 47,
            'milestones' => 6,
        ]);

        $stored = $this->reload($toolRun);

        $this->assertSame(ToolRunStatus::PendingApproval, $stored->status);
        $this->assertTrue($stored->approval_required);
        $this->assertNull($stored->approved_by);
        $this->assertStringContainsString('tasks: 47', (string) $stored->result_summary);
        $this->assertStringContainsString('Website Redesign', (string) $stored->result_summary);

        $this->assertSame(AiRunStatus::AwaitingApproval, $run->fresh()?->status);

        Notification::assertSentTo($owner, AiApprovalRequired::class);
        Notification::assertSentTo($admin, AiApprovalRequired::class);
        Notification::assertSentTo($projectManager, AiApprovalRequired::class);
        Notification::assertNotSentTo($otherManager, AiApprovalRequired::class);
        Notification::assertNotSentTo($member, AiApprovalRequired::class);
    }

    public function test_approving_twice_executes_once(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $admin = $this->makeMember($workspace, WorkspaceRole::Admin);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $run = $this->park($this->runFor($workspace, $owner));
        $toolRun = $this->pendingToolRun($run);

        $resumer = $this->fakeResumer();
        $service = app(ApprovalService::class);

        $service->approve($toolRun, $owner);
        $service->approve($this->reload($toolRun), $admin);
        $service->approve($this->reload($toolRun), $owner);

        $this->assertSame(1, $resumer->calls, 'An approved tool call was resumed more than once.');

        $stored = $this->reload($toolRun);

        $this->assertSame(ToolRunStatus::Approved, $stored->status);
        $this->assertSame($owner->getKey(), $stored->approved_by, 'A later approver overwrote the recorded decision.');
        $this->assertNotNull($stored->approved_at);
        $this->assertSame(AiRunStatus::Queued, $run->fresh()?->status);
    }

    public function test_a_member_without_the_approve_permission_cannot_approve(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $member = $this->makeMember($workspace, WorkspaceRole::Member);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $run = $this->runFor($workspace, $owner);
        $toolRun = $this->pendingToolRun($run);

        $resumer = $this->fakeResumer();

        try {
            app(ApprovalService::class)->approve($toolRun, $member);

            $this->fail('A workspace member without ai.approve approved a destructive AI action.');
        } catch (AuthorizationException) {
            // The boundary held.
        }

        $this->assertSame(ToolRunStatus::PendingApproval, $this->reload($toolRun)->status);
        $this->assertNull($this->reload($toolRun)->approved_by);
        $this->assertSame(0, $resumer->calls);
    }

    public function test_an_outsider_cannot_approve_another_workspaces_action(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $elsewhere = $this->makeWorkspace();
        $foreignOwner = $this->makeMember($elsewhere, WorkspaceRole::Owner);

        $run = $this->runFor($workspace, $owner);
        $toolRun = $this->pendingToolRun($run);

        $resumer = $this->fakeResumer();

        try {
            app(ApprovalService::class)->approve($toolRun, $foreignOwner);

            $this->fail('An owner of another workspace approved this workspace\'s AI action.');
        } catch (AuthorizationException) {
            // The boundary held.
        }

        $this->assertSame(ToolRunStatus::PendingApproval, $this->reload($toolRun)->status);
        $this->assertSame(0, $resumer->calls);
    }

    public function test_rejecting_records_the_reason_stops_the_run_and_cannot_be_overturned(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $admin = $this->makeMember($workspace, WorkspaceRole::Admin);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $run = $this->park($this->runFor($workspace, $owner));
        $toolRun = $this->pendingToolRun($run);

        $resumer = $this->fakeResumer();
        $service = app(ApprovalService::class);

        $service->reject($toolRun, $owner, 'That is not the project I meant.');

        $stored = $this->reload($toolRun);

        $this->assertSame(ToolRunStatus::Rejected, $stored->status);
        $this->assertSame('That is not the project I meant.', $stored->rejected_reason);
        $this->assertNull($stored->approved_by);
        $this->assertSame(AiRunStatus::Cancelled, $run->fresh()?->status);

        // A rejection is final: approving afterwards changes nothing and runs nothing.
        $service->approve($this->reload($toolRun), $admin);

        $this->assertSame(ToolRunStatus::Rejected, $this->reload($toolRun)->status);
        $this->assertSame(0, $resumer->calls, 'A rejected tool call was executed after the fact.');
    }

    public function test_an_approval_does_not_resume_a_run_while_the_kill_switch_is_engaged(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        $this->settingsFor($workspace, AiMode::Autonomous, [
            'kill_switch_engaged' => true,
            'kill_switch_reason' => 'Engaged during a test',
            'kill_switch_at' => Carbon::now(),
        ]);

        $run = $this->park($this->runFor($workspace, $owner));
        $toolRun = $this->pendingToolRun($run);

        $resumer = $this->fakeResumer();

        app(ApprovalService::class)->approve($toolRun, $owner);

        // The decision is on the record, but the kill switch outranks it.
        $this->assertSame(ToolRunStatus::Approved, $this->reload($toolRun)->status);
        $this->assertSame(0, $resumer->calls, 'The kill switch did not stop an approved run from resuming.');
        $this->assertSame(AiRunStatus::Cancelled, $run->fresh()?->status);
    }

    public function test_expiry_closes_approvals_nobody_answered_and_leaves_fresh_ones_alone(): void
    {
        config(['ai.approvals.approval_ttl_minutes' => 60]);

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $this->settingsFor($workspace, AiMode::Autonomous);

        $staleRun = $this->park($this->runFor($workspace, $owner));
        $stale = $this->pendingToolRun($staleRun);
        $stale->forceFill(['created_at' => Carbon::now()->subMinutes(120)])->save();

        $freshRun = $this->park($this->runFor($workspace, $owner));
        $fresh = $this->pendingToolRun($freshRun);

        $expired = app(ApprovalService::class)->expire();

        $this->assertSame(1, $expired);

        $sweptUp = $this->reload($stale);

        $this->assertSame(ToolRunStatus::Rejected, $sweptUp->status);
        $this->assertNotNull($sweptUp->rejected_reason);
        $this->assertNull($sweptUp->approved_by);
        $this->assertSame(AiRunStatus::Cancelled, $staleRun->fresh()?->status);

        $this->assertSame(ToolRunStatus::PendingApproval, $this->reload($fresh)->status);

        // Sweeping again finds nothing left to close.
        $this->assertSame(0, app(ApprovalService::class)->expire());
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function resolve(Workspace $workspace, User $user, ?Project $project = null): ResolvedPolicy
    {
        return app(PolicyResolver::class)->resolve($workspace, $project, $user);
    }

    /**
     * An enabled workspace pointed at an active provider, in the given mode.
     *
     * @param array<string, mixed> $attributes
     */
    private function settingsFor(Workspace $workspace, AiMode $mode, array $attributes = []): AiSetting
    {
        return AiSetting::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'is_enabled' => true,
            'ai_provider_id' => AiProvider::factory()->active()->create()->getKey(),
            'default_mode' => $mode,
            'autonomous_enabled' => $mode === AiMode::Autonomous,
        ] + $attributes);
    }

    private function runFor(Workspace $workspace, User $user, ?Project $project = null): AiRun
    {
        return AiRun::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'project_id' => $project?->getKey(),
            'user_id' => $user->getKey(),
            'trigger' => AiTrigger::Chat,
            'mode' => AiMode::Autonomous,
            'status' => AiRunStatus::Running,
        ]);
    }

    private function pendingToolRun(AiRun $run): AiToolRun
    {
        return AiToolRun::factory()
            ->forRun($run)
            ->pendingApproval()
            ->create();
    }

    /**
     * The state a run is genuinely in while somebody decides: stopped at the tool call,
     * waiting. `status` is not fillable — it is the agent loop's to move — so the test writes
     * it the way the loop would.
     */
    private function park(AiRun $run): AiRun
    {
        $run->forceFill(['status' => AiRunStatus::AwaitingApproval])->save();

        return $run;
    }

    /**
     * Reload without the workspace scope: nothing is bound in these tests, and the assertion
     * must read the row itself rather than whatever a scope happens to allow.
     */
    private function reload(AiToolRun $toolRun): AiToolRun
    {
        $reloaded = AiToolRun::withoutWorkspaceScope()->find($toolRun->getKey());

        $this->assertNotNull($reloaded, 'The tool run vanished.');

        return $reloaded;
    }

    /**
     * A stand-in for the agent loop's queue dispatch, counting how many times an approved call
     * was actually handed back for execution.
     */
    private function fakeResumer(): object
    {
        $resumer = new class implements ResumesApprovedRuns
        {
            public int $calls = 0;

            public function resume(AiRun $run, AiToolRun $toolRun): void
            {
                $this->calls++;
            }
        };

        $this->app->instance(ResumesApprovedRuns::class, $resumer);

        return $resumer;
    }

    private function contextFor(User $user, Workspace $workspace, ResolvedPolicy $policy): AgentContext
    {
        return new AgentContext(
            user: $user,
            workspace: $workspace,
            project: null,
            task: null,
            conversation: null,
            run: $this->runFor($workspace, $user),
            mode: $policy->mode,
            policy: $policy,
            limits: $policy->runLimits(),
            timezone: 'UTC',
        );
    }

    /**
     * Everything the model can be offered plus the elevated tools that sit above the registry.
     *
     * @return list<AiTool>
     */
    private function allTools(): array
    {
        return array_merge(app(ToolRegistry::class)->all(), $this->elevatedTools());
    }

    /**
     * @return list<AiTool>
     */
    private function mutatingTools(): array
    {
        return array_values(array_filter(
            $this->allTools(),
            static fn (AiTool $tool): bool => $tool->isMutating(),
        ));
    }

    /**
     * @return list<AiTool>
     */
    private function elevatedTools(): array
    {
        return [
            app(ArchiveProjectTool::class),
            app(UpdateProjectSettingsTool::class),
            app(ManageProjectMemberTool::class),
            app(DeleteTaskTool::class),
            app(DeleteProjectTool::class),
            app(RemoveWorkspaceMemberTool::class),
        ];
    }

    /**
     * @return list<AiTool>
     */
    private function unwaivableTools(): array
    {
        return array_values(array_filter(
            $this->elevatedTools(),
            static fn (AiTool $tool): bool => in_array($tool->name(), ElevatedTool::UNWAIVABLE, true),
        ));
    }
}
