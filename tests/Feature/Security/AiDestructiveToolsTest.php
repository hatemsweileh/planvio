<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\RunLimits;
use App\Ai\Agent\ToolResult;
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
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\AiPolicy;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four tools a workspace can never let the agent run unattended, and the blast-radius
 * numbers a person is shown before they approve one.
 *
 * AI_SECURITY.md, "Approval gating", makes a promise in plain language:
 *
 * > These four always require human approval and **cannot be waived by any policy**:
 * > `delete_project`, `delete_task`, `remove_workspace_member`, `archive_project`.
 *
 * A promise like that is worth exactly as much as the test that tries to break it. So this
 * suite does not check that a *default* configuration asks for approval — that would pass
 * even if the guarantee lived only in `config/ai.php`. It builds the most permissive world
 * the product can express: autonomous mode, an `AiPolicy` naming the tools in `allowed_tools`
 * with `max_risk: destructive`, the auto-execute ceiling raised to `destructive`, and
 * `always_require_approval` emptied outright. Then it calls the tools and asserts that
 * nothing happens.
 *
 * The other half is honesty. An approval card that reports "47 tasks" when the project has
 * three is worse than no card, because it trains people to click through, so the consequence
 * counts are asserted against a world built one record at a time.
 */
final class AiDestructiveToolsTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ *
     * The unwaivable four
     * ------------------------------------------------------------------ */

    public function test_the_unwaivable_list_matches_the_documented_four(): void
    {
        $this->assertEqualsCanonicalizing(
            ['delete_project', 'delete_task', 'remove_workspace_member', 'archive_project'],
            ElevatedTool::UNWAIVABLE,
            'The tools AI_SECURITY.md promises always need approval have changed.',
        );

        $this->assertEqualsCanonicalizing(
            ElevatedTool::UNWAIVABLE,
            config('ai.approvals.always_require_approval'),
            'config/ai.php and the code constant disagree about which tools cannot be waived.',
        );
    }

    public function test_delete_project_in_autonomous_mode_with_a_permissive_policy_still_requires_approval(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);
        $this->makeTask($project);

        $policy = $this->policyAllowing($workspace, ElevatedTool::UNWAIVABLE);

        // The policy layer refuses first, before the tool is ever reached.
        $this->assertTrue(
            $policy->requiresApproval('delete_project', AiToolRisk::Destructive),
            'A workspace policy managed to mark delete_project as auto-executable.',
        );
        $this->assertFalse($policy->canAutoExecute('delete_project', AiToolRisk::Destructive));

        $ctx = $this->contextFor($owner, $workspace, $policy);
        $result = app(DeleteProjectTool::class)->execute(['project_id' => $project->getKey()], $ctx);

        $this->assertRefusedForApproval($result);
        $this->assertFalse(
            Project::withoutWorkspaceScope()->withTrashed()->find($project->getKey())?->trashed(),
            'delete_project executed in autonomous mode without an approval.',
        );
    }

    /**
     * The real test of "cannot be waived by any policy": break every rule that is meant to
     * stop it and confirm the tools themselves still hold.
     */
    public function test_no_policy_or_configuration_can_waive_the_four(): void
    {
        // A configuration nobody should ever write: destructive work runs unattended, and the
        // unwaivable list is empty.
        config([
            'ai.approvals.auto_execute_max_risk.autonomous' => 'destructive',
            'ai.approvals.always_require_approval' => [],
        ]);

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $victim = $this->makeMember($workspace, WorkspaceRole::Member);
        $project = $this->makeProject($workspace);
        $task = $this->makeTask($project);

        $wideOpen = new ResolvedPolicy(
            mode: AiMode::Autonomous,
            maxRisk: AiToolRisk::Destructive,
            allowedTools: null,
            deniedTools: [],
            approvalRequiredTools: [],
            allowedRoles: null,
            alwaysRequireApproval: [],
        );

        // The policy object genuinely believes these may run unattended...
        foreach (['delete_project', 'delete_task', 'remove_workspace_member', 'archive_project'] as $tool) {
            $this->assertTrue(
                $wideOpen->canAutoExecute($tool, AiToolRisk::Destructive),
                "The test's premise is broken: {$tool} was still gated by the policy layer.",
            );
        }

        $ctx = $this->contextFor($owner, $workspace, $wideOpen);

        // ...and every one of them still refuses.
        $this->assertRefusedForApproval(
            app(DeleteProjectTool::class)->execute(['project_id' => $project->getKey()], $ctx),
        );
        $this->assertRefusedForApproval(
            app(ArchiveProjectTool::class)->execute(['project_id' => $project->getKey()], $ctx),
        );
        $this->assertRefusedForApproval(
            app(DeleteTaskTool::class)->execute(['task_id' => $task->getKey()], $ctx),
        );
        $this->assertRefusedForApproval(
            app(RemoveWorkspaceMemberTool::class)->execute(['user_id' => $victim->getKey()], $ctx),
        );

        $fresh = Project::withoutWorkspaceScope()->withTrashed()->find($project->getKey());

        $this->assertFalse($fresh?->trashed(), 'The project was deleted without an approval.');
        $this->assertFalse($fresh?->is_archived, 'The project was archived without an approval.');
        $this->assertFalse(
            Task::withoutWorkspaceScope()->withTrashed()->find($task->getKey())?->trashed(),
            'The task was deleted without an approval.',
        );
        $this->assertTrue(
            $workspace->members()->where('users.id', $victim->getKey())->exists(),
            'A member was removed from the workspace without an approval.',
        );
    }

    public function test_a_recorded_human_approval_lets_the_delete_through(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $ctx = $this->contextFor($owner, $workspace);
        $tool = app(DeleteProjectTool::class);
        $args = ['project_id' => $project->getKey()];

        $this->assertRefusedForApproval($tool->execute($args, $ctx));

        AiToolRun::factory()
            ->forRun($ctx->run)
            ->forTool('delete_project', AiToolRisk::Destructive)
            ->approvedBy($owner)
            ->create(['idempotency_key' => $tool->idempotencyKey($args, $ctx)]);

        $result = $tool->execute($args, $ctx);

        $this->assertTrue($result->ok, 'An approved delete_project was still refused: '.$result->summary);
        $this->assertTrue(
            Project::withoutWorkspaceScope()->withTrashed()->find($project->getKey())?->trashed(),
            'The approved delete did not happen.',
        );
    }

    /**
     * An approval is granted for one call, not for a tool. Approving the deletion of one
     * project must not license the deletion of the next one in the same run.
     */
    public function test_an_approval_does_not_carry_to_a_different_record(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $approved = $this->makeProject($workspace);
        $other = $this->makeProject($workspace);

        $ctx = $this->contextFor($owner, $workspace);
        $tool = app(DeleteProjectTool::class);

        AiToolRun::factory()
            ->forRun($ctx->run)
            ->forTool('delete_project', AiToolRisk::Destructive)
            ->approvedBy($owner)
            ->create([
                'idempotency_key' => $tool->idempotencyKey(['project_id' => $approved->getKey()], $ctx),
                'subject_type' => $approved->getMorphClass(),
                'subject_id' => $approved->getKey(),
            ]);

        $this->assertRefusedForApproval(
            $tool->execute(['project_id' => $other->getKey()], $ctx),
        );
        $this->assertFalse(
            Project::withoutWorkspaceScope()->withTrashed()->find($other->getKey())?->trashed(),
            'An approval for one project deleted a different one.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Consequences
     * ------------------------------------------------------------------ */

    public function test_delete_project_consequences_report_accurate_counts(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace, [], ['name' => 'Website Redesign']);

        $tasks = collect(range(1, 3))->map(fn (): Task => $this->makeTask($project));
        $tasks->first()->forceFill(['completed_at' => now()])->save();

        Milestone::factory()->count(2)->for($project)->create();
        WikiPage::factory()->forProject($project)->create();
        TimeEntry::factory()->count(2)->create(['project_id' => $project->getKey()]);
        ProjectMember::factory()->count(2)->create(['project_id' => $project->getKey()]);
        Activity::factory()->count(4)->about($tasks->first())->create();

        // A second project in the same workspace, so a count that forgot its project_id
        // filter would be visibly wrong rather than accidentally right.
        $decoy = $this->makeProject($workspace);
        $this->makeTask($decoy);
        Milestone::factory()->for($decoy)->create();

        $facts = app(DeleteProjectTool::class)->consequences(
            ['project_id' => $project->getKey()],
            $this->contextFor($owner, $workspace),
        );

        $this->assertSame('Website Redesign', $facts['project']);
        $this->assertSame($project->key, $facts['key']);
        $this->assertSame(3, $facts['tasks']);
        $this->assertSame(2, $facts['open_tasks']);
        $this->assertSame(2, $facts['milestones']);
        $this->assertSame(2, $facts['members']);
        $this->assertSame(2, $facts['time_entries']);
        $this->assertSame(1, $facts['wiki_pages']);
        $this->assertSame(4, $facts['activities']);
    }

    public function test_delete_task_consequences_count_the_whole_subtree(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $parent = $this->makeTask($project);
        $childA = $this->makeTask($project, ['parent_id' => $parent->getKey()]);
        $childB = $this->makeTask($project, ['parent_id' => $parent->getKey()]);
        $grandchild = $this->makeTask($project, ['parent_id' => $childA->getKey()]);

        // A sibling elsewhere in the project: it must not be counted.
        $this->makeTask($project);

        TimeEntry::factory()->create([
            'project_id' => $project->getKey(),
            'task_id' => $grandchild->getKey(),
            'minutes' => 90,
        ]);
        TimeEntry::factory()->create([
            'project_id' => $project->getKey(),
            'task_id' => $parent->getKey(),
            'minutes' => 30,
        ]);
        Activity::factory()->count(2)->about($childB)->create();

        $facts = app(DeleteTaskTool::class)->consequences(
            ['task_id' => $parent->getKey()],
            $this->contextFor($owner, $workspace),
        );

        // The tool resolves the task with its project loaded, so the card shows "WEB-1"
        // rather than the "#1" a bare task renders.
        $this->assertSame($project->taskKey($parent->number), $facts['task']);
        $this->assertSame(3, $facts['subtasks'], 'The subtask tree was not walked to the bottom.');
        $this->assertSame(2, $facts['time_entries']);
        $this->assertSame(120, $facts['logged_minutes']);
        $this->assertSame(2, $facts['activities']);
    }

    public function test_remove_workspace_member_consequences_report_what_moves_and_what_stays(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $leaver = $this->makeMember($workspace, WorkspaceRole::Member);

        $project = $this->makeProject($workspace, [[$leaver, ProjectRole::Member]]);
        $this->makeTask($project, ['assignee_id' => $leaver->getKey()]);
        $this->makeTask($project, ['assignee_id' => $leaver->getKey()]);
        $this->makeTask($project, ['assignee_id' => $owner->getKey()]);

        $facts = app(RemoveWorkspaceMemberTool::class)->consequences(
            ['user_id' => $leaver->getKey()],
            $this->contextFor($owner, $workspace),
        );

        $this->assertSame($leaver->name, $facts['member']);
        $this->assertSame(WorkspaceRole::Member->value, $facts['role']);
        $this->assertFalse($facts['is_last_owner']);
        $this->assertSame(2, $facts['open_tasks']);
        $this->assertSame(1, $facts['projects']);
    }

    /**
     * The card must describe the world as it is now, so that "47 tasks" is 47 tasks — not
     * whatever is left after the Action has run.
     */
    public function test_the_executed_result_carries_the_same_counts_the_card_showed(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $this->makeTask($project);
        $this->makeTask($project);
        Milestone::factory()->for($project)->create();

        $ctx = $this->contextFor($owner, $workspace);
        $tool = app(DeleteProjectTool::class);
        $args = ['project_id' => $project->getKey()];

        $card = $tool->consequences($args, $ctx);

        AiToolRun::factory()
            ->forRun($ctx->run)
            ->forTool('delete_project', AiToolRisk::Destructive)
            ->approvedBy($owner)
            ->create(['idempotency_key' => $tool->idempotencyKey($args, $ctx)]);

        $result = $tool->execute($args, $ctx);

        $this->assertTrue($result->ok);
        $this->assertSame(2, $card['tasks']);
        $this->assertSame($card['tasks'], $result->data['tasks']);
        $this->assertSame($card['milestones'], $result->data['milestones']);
    }

    public function test_consequences_are_empty_rather_than_invented_for_a_record_that_is_not_here(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $elsewhere = $this->makeProject($this->makeWorkspace());

        $ctx = $this->contextFor($owner, $workspace);

        $this->assertSame([], app(DeleteProjectTool::class)->consequences(['project_id' => $elsewhere->getKey()], $ctx));
        $this->assertSame([], app(DeleteProjectTool::class)->consequences(['project_id' => 999_999], $ctx));
        $this->assertSame([], app(DeleteProjectTool::class)->consequences([], $ctx));
    }

    /* ------------------------------------------------------------------ *
     * Tenancy
     * ------------------------------------------------------------------ */

    /**
     * The acting user is an owner of *both* workspaces, so permissions are not what stops
     * this: only the workspace the run is bound to is. And the refusal must not tell the
     * model that the id it named is real somewhere else.
     */
    public function test_a_project_id_from_another_workspace_is_not_found_and_the_refusal_reveals_nothing(): void
    {
        $here = $this->makeWorkspace();
        $there = $this->makeWorkspace();

        $user = $this->makeMember($here, WorkspaceRole::Owner);
        $there->members()->attach($user->getKey(), [
            'role' => WorkspaceRole::Owner->value,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $user->refresh();

        $foreign = $this->makeProject($there, [], [
            'name' => 'Confidential Acquisition',
            'key' => 'ACQ',
        ]);

        $ctx = $this->contextFor($user, $here);
        $tool = app(DeleteProjectTool::class);

        $foreignResult = $tool->execute(['project_id' => $foreign->getKey()], $ctx);
        $fictionalResult = $tool->execute(['project_id' => 987_654], $ctx);

        $this->assertFalse($foreignResult->ok);
        $this->assertSame('not_found', $foreignResult->error);

        foreach (['Confidential Acquisition', 'ACQ', $foreign->slug] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase(
                (string) $secret,
                $foreignResult->summary,
                'The refusal leaked a detail of a record in another workspace.',
            );
        }

        // The two refusals must be indistinguishable once the echoed id is masked: anything
        // else is an oracle a model could enumerate workspace ids against.
        $this->assertSame(
            str_replace('987654', 'X', $fictionalResult->summary),
            str_replace((string) $foreign->getKey(), 'X', $foreignResult->summary),
            'A cross-workspace id produced a different answer from a non-existent one.',
        );

        $this->assertFalse(
            Project::withoutWorkspaceScope()->withTrashed()->find($foreign->getKey())?->trashed(),
            'A project in another workspace was deleted.',
        );
    }

    public function test_a_user_id_from_another_workspace_cannot_be_removed(): void
    {
        $here = $this->makeWorkspace();
        $there = $this->makeWorkspace();

        $owner = $this->makeMember($here, WorkspaceRole::Owner);
        $stranger = $this->makeMember($there, WorkspaceRole::Member);

        $result = app(RemoveWorkspaceMemberTool::class)->execute(
            ['user_id' => $stranger->getKey()],
            $this->contextFor($owner, $here),
        );

        $this->assertFalse($result->ok);
        $this->assertSame('not_found', $result->error);
        $this->assertStringNotContainsString($stranger->name, $result->summary);
        $this->assertTrue(
            $there->members()->where('users.id', $stranger->getKey())->exists(),
            'A member of another workspace was removed.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Layered authorization on the high-risk pair
     * ------------------------------------------------------------------ */

    /**
     * A workspace `manager` holds `project.update` inside projects they manage (`+`) but
     * never holds `budget.manage` at all. One tool, two different answers — which is the
     * whole reason `update_project_settings` asks the Gate more than once.
     */
    public function test_update_project_settings_authorises_money_separately_from_the_rest(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->makeProject($workspace, [[$manager, ProjectRole::Manager]], [
            'client_name' => null,
            'budget' => null,
        ]);

        $ctx = $this->contextFor($manager, $workspace);
        $tool = app(UpdateProjectSettingsTool::class);

        $allowed = $tool->execute(['project_id' => $project->getKey(), 'client_name' => 'Acme'], $ctx);

        $this->assertTrue($allowed->ok, 'A project manager was refused an ordinary settings change: '.$allowed->summary);
        $this->assertSame('Acme', $project->fresh()?->client_name);

        $refused = $tool->execute(['project_id' => $project->getKey(), 'budget' => 5000], $ctx);

        $this->assertFalse($refused->ok);
        $this->assertSame('permission_denied', $refused->error);
        $this->assertNull($project->fresh()?->budget, 'The budget moved without budget.manage.');
    }

    public function test_update_project_settings_refuses_fields_outside_its_allow_list(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace, [], ['name' => 'Original']);

        $result = app(UpdateProjectSettingsTool::class)->execute(
            ['project_id' => $project->getKey(), 'name' => 'Renamed', 'key' => 'NEW'],
            $this->contextFor($owner, $workspace),
        );

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_arguments', $result->error);
        $this->assertSame('Original', $project->fresh()?->name);
    }

    public function test_manage_project_member_grants_the_manager_role_and_polices_its_own_argument_shape(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $member = $this->makeMember($workspace, WorkspaceRole::Member);
        $project = $this->makeProject($workspace);

        $ctx = $this->contextFor($owner, $workspace);
        $tool = app(ManageProjectMemberTool::class);

        $added = $tool->execute([
            'project_id' => $project->getKey(),
            'user_id' => $member->getKey(),
            'operation' => 'add',
            'role' => 'manager',
        ], $ctx);

        $this->assertTrue($added->ok, $added->summary);
        $this->assertSame(
            ProjectRole::Manager,
            ProjectMember::query()
                ->where('project_id', $project->getKey())
                ->where('user_id', $member->getKey())
                ->first()?->role,
        );

        $malformed = $tool->execute([
            'project_id' => $project->getKey(),
            'user_id' => $member->getKey(),
            'operation' => 'remove',
            'role' => 'member',
        ], $ctx);

        $this->assertFalse($malformed->ok);
        $this->assertSame('invalid_arguments', $malformed->error);
        $this->assertTrue(
            ProjectMember::query()
                ->where('project_id', $project->getKey())
                ->where('user_id', $member->getKey())
                ->exists(),
            'A malformed call still removed the membership.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Declarations the runner gates on
     * ------------------------------------------------------------------ */

    public function test_every_elevated_tool_declares_itself_correctly(): void
    {
        $expected = [
            ArchiveProjectTool::class => ['archive_project', AiToolRisk::High],
            UpdateProjectSettingsTool::class => ['update_project_settings', AiToolRisk::High],
            ManageProjectMemberTool::class => ['manage_project_member', AiToolRisk::High],
            DeleteTaskTool::class => ['delete_task', AiToolRisk::Destructive],
            DeleteProjectTool::class => ['delete_project', AiToolRisk::Destructive],
            RemoveWorkspaceMemberTool::class => ['remove_workspace_member', AiToolRisk::Destructive],
        ];

        foreach ($expected as $class => [$name, $risk]) {
            $tool = app($class);

            $this->assertSame($name, $tool->name());
            $this->assertSame($risk, $tool->risk());
            $this->assertTrue($tool->isMutating(), "{$name} must declare itself mutating.");
            $this->assertNotNull($tool->permission(), "{$name} must name the permission it is gated on.");
            $this->assertFalse(
                $tool->parameters()['additionalProperties'] ?? true,
                "{$name} must reject arguments its schema does not declare.",
            );
        }
    }

    public function test_an_argument_the_schema_does_not_declare_is_rejected(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $result = app(DeleteProjectTool::class)->execute(
            ['project_id' => $project->getKey(), 'force' => true],
            $this->contextFor($owner, $workspace),
        );

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_arguments', $result->error);
        $this->assertFalse(
            Project::withoutWorkspaceScope()->withTrashed()->find($project->getKey())?->trashed(),
        );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function assertRefusedForApproval(ToolResult $result): void
    {
        $this->assertFalse($result->ok, 'An unwaivable tool executed without an approval.');
        $this->assertSame('approval_required', $result->error, 'Refused, but not for want of an approval: '.$result->summary);
        $this->assertTrue($result->data['approval_required'] ?? false);
    }

    /**
     * An autonomous run for $user in $workspace, with the most permissive policy the caller
     * asks for.
     */
    private function contextFor(User $user, Workspace $workspace, ?ResolvedPolicy $policy = null): AgentContext
    {
        $settings = $this->settingsFor($workspace);

        $run = AiRun::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'mode' => AiMode::Autonomous,
            'trigger' => AiTrigger::Chat,
            'status' => AiRunStatus::Running,
        ]);

        return new AgentContext(
            user: $user,
            workspace: $workspace,
            project: null,
            task: null,
            conversation: null,
            run: $run,
            mode: AiMode::Autonomous,
            policy: $policy ?? ResolvedPolicy::resolve($settings),
            limits: RunLimits::fromSettings($settings),
            timezone: 'UTC',
        );
    }

    /**
     * A policy stack that names the tools in `allowed_tools`, raises `max_risk` to
     * destructive and demands approval for nothing — everything a workspace administrator
     * could do to try to let these run unattended.
     *
     * @param list<string> $tools
     */
    private function policyAllowing(Workspace $workspace, array $tools): ResolvedPolicy
    {
        $policy = AiPolicy::factory()
            ->allowing($tools)
            ->maxRisk(AiToolRisk::Destructive)
            ->forMode(AiMode::Autonomous)
            ->create(['workspace_id' => $workspace->getKey()]);

        return ResolvedPolicy::resolve($this->settingsFor($workspace), [$policy]);
    }

    private function settingsFor(Workspace $workspace): AiSetting
    {
        // `ai_settings` deliberately does not register WorkspaceScope — the global row is a
        // tier of its own — so the workspace has to be named explicitly here.
        return AiSetting::query()
            ->where('workspace_id', $workspace->getKey())
            ->first()
            ?? AiSetting::factory()->autonomous()->create(['workspace_id' => $workspace->getKey()]);
    }
}
