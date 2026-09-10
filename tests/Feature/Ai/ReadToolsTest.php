<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\RunLimits;
use App\Ai\Agent\ToolRegistry;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Policy\ResolvedPolicy;
use App\Enums\AiMode;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\ProjectRole;
use App\Enums\WikiVisibility;
use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\AiPolicy;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The read tools' containment properties, asserted directly.
 *
 * Two questions are asked of every tool here, and neither is "does it return the right thing
 * for an owner". That answer tells you nothing: the failure modes that matter are a record
 * from another tenant appearing in a result, and a role seeing a figure its permission does
 * not carry. Both are invisible unless a test names the record it must not find.
 *
 * The registry is tested for the same reason: a name it does not know must produce nothing
 * at all, and assistant mode must be enforced by what the model is handed rather than by
 * what it is told.
 */
final class ReadToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every tool this task delivers. Named rather than derived from the registry, so that a
     * tool silently disappearing from the list fails the suite.
     */
    private const READ_TOOLS = [
        'search_projects',
        'search_tasks',
        'get_project',
        'get_task',
        'get_workspace_overview',
        'get_project_health',
        'get_team_workload',
        'get_time_report',
        'get_budget_summary',
        'list_project_members',
        'search_wiki',
        'get_activity',
    ];

    /* ------------------------------------------------------------------ *
     * The registry
     * ------------------------------------------------------------------ */

    #[Test]
    public function every_read_tool_is_registered_and_declares_itself_as_a_read(): void
    {
        $registry = new ToolRegistry;

        foreach (self::READ_TOOLS as $name) {
            $tool = $registry->resolve($name);

            $this->assertInstanceOf(AiTool::class, $tool, "{$name} is not in the registry.");
            $this->assertSame($name, $tool->name());
            $this->assertSame('read', $tool->group());
            $this->assertSame(AiToolRisk::Read, $tool->risk());
            $this->assertFalse($tool->isMutating(), "{$name} claims to mutate.");
            $this->assertNotSame('', trim($tool->description()));

            $schema = $tool->parameters();

            $this->assertSame('object', $schema['type'] ?? null, "{$name} does not declare an object schema.");
            $this->assertFalse(
                $schema['additionalProperties'] ?? true,
                "{$name} would accept arguments its schema does not describe.",
            );
        }
    }

    #[Test]
    public function a_name_the_registry_does_not_know_resolves_to_nothing(): void
    {
        $registry = new ToolRegistry;

        // None of these may become a class, a guess or a near match. They must be nothing.
        $invented = [
            'delete_everything',
            'App\Models\User',
            'App\Ai\Tools\Read\GetTaskTool',
            '../../../etc/passwd',
            'GetTask',
            'get task',
            'get_task; drop table tasks',
            '',
        ];

        foreach ($invented as $name) {
            $this->assertNull($registry->resolve($name), "\"{$name}\" resolved to a tool.");
            $this->assertFalse($registry->has($name));
        }
    }

    #[Test]
    public function registered_tool_names_are_unique_and_schemas_are_provider_ready(): void
    {
        $registry = new ToolRegistry;

        $names = $registry->names();

        $this->assertSame($names, array_values(array_unique($names)));

        foreach ($registry->schemas() as $schema) {
            $this->assertArrayHasKey('name', $schema);
            $this->assertArrayHasKey('description', $schema);
            $this->assertArrayHasKey('parameters', $schema);
            $this->assertIsArray($schema['parameters']);
            $this->assertNotNull($registry->resolve($schema['name']));
        }
    }

    #[Test]
    public function assistant_mode_is_handed_no_mutating_tool(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        $context = $this->contextFor($owner, $workspace, mode: AiMode::Assistant);

        $offered = (new ToolRegistry)->forContext($context);

        $this->assertNotSame([], $offered);

        foreach ($offered as $tool) {
            $this->assertFalse(
                $tool->isMutating(),
                "{$tool->name()} was offered in assistant mode, which holds no mutating tools.",
            );
        }
    }

    #[Test]
    public function a_policy_that_denies_a_tool_stops_it_being_offered(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        $settings = AiSetting::factory()->make(['workspace_id' => $workspace->id]);

        $policy = ResolvedPolicy::resolve($settings, [
            AiPolicy::factory()->make([
                'workspace_id' => $workspace->id,
                'mode' => null,
                'allowed_tools' => ['search_tasks', 'get_task', 'get_budget_summary'],
                'denied_tools' => ['get_budget_summary'],
                'approval_required_tools' => [],
                'is_active' => true,
            ]),
        ]);

        $context = $this->contextFor($owner, $workspace, policy: $policy);

        $offered = array_map(
            static fn (AiTool $tool): string => $tool->name(),
            (new ToolRegistry)->forContext($context),
        );

        $this->assertContains('search_tasks', $offered);
        $this->assertContains('get_task', $offered);
        $this->assertNotContains('get_budget_summary', $offered, 'An explicit deny did not win.');
        $this->assertNotContains('search_projects', $offered, 'An allow-list did not exclude what it omits.');
    }

    #[Test]
    public function a_run_for_someone_who_is_not_a_member_is_offered_nothing(): void
    {
        $workspace = $this->makeWorkspace();
        $outsider = User::factory()->create();

        $context = $this->contextFor($outsider, $workspace);

        $this->assertSame([], (new ToolRegistry)->forContext($context));
    }

    /* ------------------------------------------------------------------ *
     * Workspace isolation
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_project_in_another_workspace_cannot_be_read_by_id(): void
    {
        $home = $this->makeWorkspace(['name' => 'Northwind Home']);
        $foreign = $this->makeWorkspace(['name' => 'Contoso Foreign']);

        $owner = $this->makeMember($home, WorkspaceRole::Owner);
        $secret = $this->makeProject($foreign, attributes: ['name' => 'Merger Diligence', 'key' => 'MRGR']);

        $context = $this->contextFor($owner, $home);

        foreach (['get_project', 'get_project_health', 'get_budget_summary', 'list_project_members'] as $name) {
            $result = $this->callTool($name, ['project' => (int) $secret->id], $context);

            $this->assertFalse($result->ok, "{$name} read a project from another workspace.");
            $this->assertStringNotContainsString('Merger Diligence', json_encode($result->toArray()) ?: '');
        }

        // The key form must fail the same way as the id form.
        $byKey = $this->callTool('get_project', ['project' => 'MRGR'], $context);

        $this->assertFalse($byKey->ok);
    }

    #[Test]
    public function a_task_in_another_workspace_cannot_be_read_by_id_or_key(): void
    {
        $home = $this->makeWorkspace();
        $foreign = $this->makeWorkspace();

        $owner = $this->makeMember($home, WorkspaceRole::Owner);
        $foreignProject = $this->makeProject($foreign, attributes: ['key' => 'FRGN']);
        $foreignTask = $this->makeTask($foreignProject, ['title' => 'Rotate production credentials']);

        $context = $this->contextFor($owner, $home);

        $byId = $this->callTool('get_task', ['task' => (int) $foreignTask->id], $context);
        $byKey = $this->callTool('get_task', ['task' => 'FRGN-'.$foreignTask->number], $context);

        $this->assertFalse($byId->ok);
        $this->assertFalse($byKey->ok);
        $this->assertStringNotContainsString('Rotate production credentials', json_encode($byId->toArray()) ?: '');
    }

    #[Test]
    public function searches_never_cross_a_workspace_boundary(): void
    {
        $home = $this->makeWorkspace();
        $foreign = $this->makeWorkspace();

        $owner = $this->makeMember($home, WorkspaceRole::Owner);

        $mine = $this->makeProject($home, attributes: ['name' => 'Atlas Rollout']);
        $this->makeTask($mine, ['title' => 'Atlas kickoff workshop']);

        $theirs = $this->makeProject($foreign, attributes: ['name' => 'Atlas Confidential']);
        $this->makeTask($theirs, ['title' => 'Atlas confidential handover']);

        WikiPage::factory()->create([
            'workspace_id' => $foreign->id,
            'title' => 'Atlas confidential runbook',
        ]);

        $context = $this->contextFor($owner, $home);

        $projects = $this->callTool('search_projects', ['query' => 'Atlas'], $context);
        $tasks = $this->callTool('search_tasks', ['query' => 'Atlas'], $context);
        $wiki = $this->callTool('search_wiki', ['query' => 'Atlas'], $context);

        $this->assertTrue($projects->ok);
        $this->assertTrue($tasks->ok);
        $this->assertTrue($wiki->ok);

        $encoded = json_encode([$projects->data, $tasks->data, $wiki->data]) ?: '';

        $this->assertStringContainsString('Atlas Rollout', $encoded);
        $this->assertStringNotContainsString('Atlas Confidential', $encoded);
        $this->assertStringNotContainsString('Atlas confidential handover', $encoded);
        $this->assertStringNotContainsString('Atlas confidential runbook', $encoded);
    }

    #[Test]
    public function the_activity_feed_stops_at_the_workspace_boundary(): void
    {
        $home = $this->makeWorkspace();
        $foreign = $this->makeWorkspace();

        $owner = $this->makeMember($home, WorkspaceRole::Owner);

        $mine = $this->makeProject($home);
        $theirs = $this->makeProject($foreign);

        Activity::factory()->create([
            'subject_id' => $this->makeTask($mine)->id,
            'event' => 'created',
            'description' => 'Home workspace entry',
        ]);

        Activity::factory()->create([
            'subject_id' => $this->makeTask($theirs)->id,
            'event' => 'created',
            'description' => 'Foreign workspace entry',
        ]);

        $result = $this->callTool('get_activity', [], $this->contextFor($owner, $home));

        $this->assertTrue($result->ok);

        $encoded = json_encode($result->data) ?: '';

        $this->assertStringContainsString('Home workspace entry', $encoded);
        $this->assertStringNotContainsString('Foreign workspace entry', $encoded);
    }

    /* ------------------------------------------------------------------ *
     * A guest gets only their projects
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_guest_sees_only_the_projects_they_were_added_to(): void
    {
        $workspace = $this->makeWorkspace();
        $guest = $this->makeMember($workspace, WorkspaceRole::Guest);

        $theirs = $this->makeProject($workspace, [[$guest, ProjectRole::Guest]], ['name' => 'Client Portal']);
        $other = $this->makeProject($workspace, attributes: ['name' => 'Internal Payroll']);

        $this->makeTask($theirs, ['title' => 'Portal login page']);
        $this->makeTask($other, ['title' => 'Payroll reconciliation']);

        $context = $this->contextFor($guest, $workspace);

        $projects = $this->callTool('search_projects', [], $context);

        $this->assertTrue($projects->ok);
        $this->assertSame(1, $projects->data['total']);
        $this->assertSame('Client Portal', $projects->data['projects'][0]['name']);

        $tasks = $this->callTool('search_tasks', [], $context);

        $this->assertTrue($tasks->ok);
        $this->assertSame(1, $tasks->data['total']);
        $this->assertSame('Portal login page', $tasks->data['tasks'][0]['title']);

        $overview = $this->callTool('get_workspace_overview', [], $context);

        $this->assertTrue($overview->ok);
        $this->assertSame(1, $overview->data['counts']['active_projects']);
        $this->assertStringNotContainsString('Internal Payroll', json_encode($overview->data) ?: '');
    }

    #[Test]
    public function a_guest_cannot_open_a_project_they_are_not_a_member_of(): void
    {
        $workspace = $this->makeWorkspace();
        $guest = $this->makeMember($workspace, WorkspaceRole::Guest);

        $theirs = $this->makeProject($workspace, [[$guest, ProjectRole::Guest]]);
        $other = $this->makeProject($workspace, attributes: ['name' => 'Internal Payroll']);
        $hidden = $this->makeTask($other, ['title' => 'Payroll reconciliation']);

        $context = $this->contextFor($guest, $workspace);

        $ownProject = $this->callTool('get_project', ['project' => (int) $theirs->id], $context);
        $foreignProject = $this->callTool('get_project', ['project' => (int) $other->id], $context);
        $foreignTask = $this->callTool('get_task', ['task' => (int) $hidden->id], $context);
        $members = $this->callTool('list_project_members', ['project' => (int) $other->id], $context);

        $this->assertTrue($ownProject->ok);
        $this->assertFalse($foreignProject->ok);
        $this->assertFalse($foreignTask->ok);
        $this->assertFalse($members->ok);

        $this->assertStringNotContainsString(
            'Payroll reconciliation',
            json_encode([$foreignProject->toArray(), $foreignTask->toArray()]) ?: '',
        );
    }

    #[Test]
    public function a_guest_cannot_run_a_workspace_report(): void
    {
        $workspace = $this->makeWorkspace();
        $guest = $this->makeMember($workspace, WorkspaceRole::Guest);

        $this->makeProject($workspace, [[$guest, ProjectRole::Guest]]);

        $context = $this->contextFor($guest, $workspace);

        $workload = $this->callTool('get_team_workload', [], $context);
        $time = $this->callTool('get_time_report', [], $context);

        $this->assertTrue($workload->wasDenied(), 'A guest reached the workspace workload report.');
        $this->assertTrue($time->wasDenied(), 'A guest reached the workspace time report.');
    }

    /* ------------------------------------------------------------------ *
     * Permission filtering inside a workspace
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_member_without_budget_view_is_refused_the_budget(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Member);
        $admin = $this->makeMember($workspace, WorkspaceRole::Admin);

        $project = $this->makeProject($workspace, attributes: [
            'budget' => '10000.00',
            'currency' => 'USD',
        ]);

        Expense::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'amount' => '2500.00',
            'currency' => 'USD',
        ]);

        $memberResult = $this->callTool('get_budget_summary', ['project' => (int) $project->id], $this->contextFor($member, $workspace));

        $this->assertTrue($memberResult->wasDenied());

        $adminResult = $this->callTool('get_budget_summary', ['project' => (int) $project->id], $this->contextFor($admin, $workspace));

        $this->assertTrue($adminResult->ok);
        $this->assertSame('2500.00', $adminResult->data['actual']);
        $this->assertSame('10000.00', $adminResult->data['planned']);

        // The same rule holds through the project reader: the section is withheld, and its
        // absence is stated rather than left to be read as "no budget".
        $projectForMember = $this->callTool('get_project', ['project' => (int) $project->id], $this->contextFor($member, $workspace));

        $this->assertTrue($projectForMember->ok);
        $this->assertArrayNotHasKey('budget', $projectForMember->data);
        $this->assertArrayHasKey('budget_note', $projectForMember->data);
    }

    #[Test]
    public function a_time_report_is_narrowed_to_the_caller_without_time_view_all(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Member);
        $colleague = $this->makeMember($workspace, WorkspaceRole::Member);
        $admin = $this->makeMember($workspace, WorkspaceRole::Admin);

        $project = $this->makeProject($workspace);

        TimeEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $member->id,
            'minutes' => 60,
            'spent_on' => now()->toDateString(),
        ]);

        TimeEntry::factory()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project->id,
            'user_id' => $colleague->id,
            'minutes' => 300,
            'spent_on' => now()->toDateString(),
        ]);

        $ownReport = $this->callTool('get_time_report', [], $this->contextFor($member, $workspace));

        $this->assertTrue($ownReport->ok);
        $this->assertTrue($ownReport->data['limited_to_own_entries']);
        $this->assertSame(60, $ownReport->data['total_minutes']);

        $byPerson = $this->callTool('get_time_report', ['group_by' => 'user'], $this->contextFor($member, $workspace));

        $this->assertTrue($byPerson->wasDenied(), 'A member grouped a time report by person.');

        $adminReport = $this->callTool('get_time_report', ['group_by' => 'user'], $this->contextFor($admin, $workspace));

        $this->assertTrue($adminReport->ok);
        $this->assertFalse($adminReport->data['limited_to_own_entries']);
        $this->assertSame(360, $adminReport->data['total_minutes']);
    }

    #[Test]
    public function a_private_wiki_page_stays_with_its_author(): void
    {
        $workspace = $this->makeWorkspace();
        $author = $this->makeMember($workspace, WorkspaceRole::Member);
        $colleague = $this->makeMember($workspace, WorkspaceRole::Member);

        WikiPage::factory()->create([
            'workspace_id' => $workspace->id,
            'author_id' => $author->id,
            'visibility' => WikiVisibility::Private,
            'title' => 'Salary review notes',
            'content' => '<p>Salary review notes for the quarter.</p>',
        ]);

        $mine = $this->callTool('search_wiki', ['query' => 'Salary'], $this->contextFor($author, $workspace));
        $theirs = $this->callTool('search_wiki', ['query' => 'Salary'], $this->contextFor($colleague, $workspace));

        $this->assertTrue($mine->ok);
        $this->assertSame(1, $mine->data['total']);

        $this->assertTrue($theirs->ok);
        $this->assertSame(0, $theirs->data['total']);
        $this->assertStringNotContainsString('Salary review notes', json_encode($theirs->data) ?: '');
    }

    #[Test]
    public function project_members_are_listed_with_both_roles(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager, ['name' => 'Dana Reed']);
        $guest = $this->makeMember($workspace, WorkspaceRole::Guest, ['name' => 'Ola Vance']);

        $project = $this->makeProject($workspace, [
            [$manager, ProjectRole::Manager],
            [$guest, ProjectRole::Guest],
        ]);

        $result = $this->callTool('list_project_members', ['project' => (int) $project->id], $this->contextFor($manager, $workspace));

        $this->assertTrue($result->ok);
        $this->assertSame(2, $result->data['total']);

        $roles = [];

        foreach ($result->data['members'] as $member) {
            $roles[$member['name']] = [$member['project_role'], $member['workspace_role']];
        }

        $this->assertSame(['manager', 'manager'], $roles['Dana Reed']);
        $this->assertSame(['guest', 'guest'], $roles['Ola Vance']);
    }

    /* ------------------------------------------------------------------ *
     * Facts, assessments and honest truncation
     * ------------------------------------------------------------------ */

    #[Test]
    public function project_health_separates_the_recorded_value_from_the_assessment(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        foreach (range(1, 4) as $index) {
            $this->makeTask($project, [
                'title' => 'Late item '.$index,
                'due_date' => now()->subDays(10 + $index)->toDateString(),
            ]);
        }

        $result = $this->callTool('get_project_health', ['project' => (int) $project->id], $this->contextFor($owner, $workspace));

        $this->assertTrue($result->ok);

        $this->assertArrayHasKey('stored_health', $result->data);
        $this->assertArrayHasKey('facts', $result->data);
        $this->assertSame('assessment', $result->data['assessment']['kind']);
        $this->assertNotSame('', $result->data['note']);

        $this->assertSame(4, $result->data['facts']['tasks']['overdue']);
        $this->assertNotNull($result->data['facts']['tasks']['oldest_overdue_due_date']);
        $this->assertNotSame([], $result->data['assessment']['signals']);
    }

    #[Test]
    public function a_capped_list_says_how_much_it_did_not_return(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        Task::factory()->count(12)->create([
            'project_id' => $project->id,
            'workspace_id' => $workspace->id,
        ]);

        $result = $this->callTool('search_tasks', ['limit' => 5], $this->contextFor($owner, $workspace));

        $this->assertTrue($result->ok);
        $this->assertSame(5, $result->data['returned']);
        $this->assertSame(12, $result->data['total']);
        $this->assertSame(7, $result->data['omitted']);
        $this->assertCount(5, $result->data['tasks']);
    }

    #[Test]
    public function a_tool_result_never_exceeds_the_configured_character_budget(): void
    {
        config()->set('ai.limits.max_tool_result_chars', 1200);

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        Task::factory()->count(40)->create([
            'project_id' => $project->id,
            'workspace_id' => $workspace->id,
            'description' => str_repeat('Long description text. ', 40),
        ]);

        $result = $this->callTool('search_tasks', ['limit' => 40], $this->contextFor($owner, $workspace));

        $this->assertTrue($result->ok);
        $this->assertLessThanOrEqual(1200, mb_strlen(json_encode($result->data) ?: ''));
        $this->assertGreaterThan(0, $result->data['omitted']);
        $this->assertSame(40, $result->data['total']);
    }

    /* ------------------------------------------------------------------ *
     * Argument validation
     * ------------------------------------------------------------------ */

    #[Test]
    public function arguments_the_schema_does_not_describe_are_rejected(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $context = $this->contextFor($owner, $workspace);

        $extra = $this->callTool('get_project', ['project' => (int) $project->id, 'include_secrets' => true], $context);

        $this->assertFalse($extra->ok);
        $this->assertSame('invalid_arguments', $extra->error);

        $missing = $this->callTool('get_project', [], $context);

        $this->assertFalse($missing->ok);
        $this->assertSame('invalid_arguments', $missing->error);

        $wrongEnum = $this->callTool('search_projects', ['health' => 'catastrophic'], $context);

        $this->assertFalse($wrongEnum->ok);
        $this->assertSame('invalid_arguments', $wrongEnum->error);

        $wrongType = $this->callTool('search_tasks', ['overdue' => 'perhaps'], $context);

        $this->assertFalse($wrongType->ok);
        $this->assertSame('invalid_arguments', $wrongType->error);

        $contradiction = $this->callTool('search_tasks', ['assignee' => 'me', 'unassigned' => true], $context);

        $this->assertFalse($contradiction->ok);
    }

    #[Test]
    public function filters_narrow_the_task_search_the_way_they_claim_to(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $mine = $this->makeTask($project, [
            'title' => 'Draft the migration plan',
            'assignee_id' => $owner->id,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->makeTask($project, [
            'title' => 'Nobody owns this yet',
            'assignee_id' => null,
            'due_date' => now()->addMonth()->toDateString(),
        ]);

        $context = $this->contextFor($owner, $workspace);

        $assigned = $this->callTool('search_tasks', ['assignee' => 'me'], $context);
        $unassigned = $this->callTool('search_tasks', ['unassigned' => true], $context);
        $overdue = $this->callTool('search_tasks', ['overdue' => true], $context);
        $byProjectKey = $this->callTool('search_tasks', ['project' => (string) $project->key], $context);

        $this->assertSame(1, $assigned->data['total']);
        $this->assertSame((int) $mine->id, $assigned->data['tasks'][0]['id']);

        $this->assertSame(1, $unassigned->data['total']);
        $this->assertSame('Nobody owns this yet', $unassigned->data['tasks'][0]['title']);

        $this->assertSame(1, $overdue->data['total']);
        $this->assertTrue($overdue->data['tasks'][0]['is_overdue']);

        $this->assertSame(2, $byProjectKey->data['total']);
    }

    #[Test]
    public function a_task_is_returned_with_the_detail_the_tool_promises(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->makeProject($workspace);

        $task = $this->makeTask($project, ['title' => 'Ship the beta']);
        $this->makeTask($project, ['title' => 'Write the release note', 'parent_id' => $task->id]);

        $result = $this->callTool('get_task', ['task' => (int) $task->id], $this->contextFor($owner, $workspace));

        $this->assertTrue($result->ok);
        $this->assertSame('Ship the beta', $result->data['title']);
        $this->assertSame(1, $result->data['subtasks']['total']);
        $this->assertArrayHasKey('checklist', $result->data);
        $this->assertArrayHasKey('blocked_by', $result->data);
        $this->assertArrayHasKey('blocks', $result->data);
        $this->assertArrayHasKey('comments', $result->data);
        $this->assertInstanceOf(Task::class, $result->subject);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $args
     */
    private function callTool(string $name, array $args, AgentContext $context): ToolResult
    {
        $tool = (new ToolRegistry)->resolve($name);

        $this->assertInstanceOf(AiTool::class, $tool, "{$name} is not registered.");

        return $tool->execute($args, $context);
    }

    private function contextFor(
        User $user,
        Workspace $workspace,
        ?Project $project = null,
        ?Task $task = null,
        AiMode $mode = AiMode::Copilot,
        ?ResolvedPolicy $policy = null,
    ): AgentContext {
        $settings = AiSetting::factory()->make(['workspace_id' => $workspace->id]);

        $run = AiRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project?->id,
            'user_id' => $user->id,
            'trigger' => 'chat',
            'mode' => $mode,
            'status' => 'queued',
        ]);

        return new AgentContext(
            user: $user,
            workspace: $workspace,
            project: $project,
            task: $task,
            conversation: null,
            run: $run,
            mode: $mode,
            policy: $policy ?? ResolvedPolicy::resolve($settings),
            limits: RunLimits::fromSettings($settings),
            timezone: (string) $workspace->timezone,
        );
    }

    /**
     * Guards the assumption every permission assertion here rests on: `Permission` is the
     * vocabulary the tools ask in, and a case disappearing from it would make a denial pass
     * for the wrong reason.
     */
    #[Test]
    public function the_permissions_the_read_tools_declare_all_exist(): void
    {
        $declared = [];

        foreach ((new ToolRegistry)->all() as $tool) {
            $permission = $tool->permission();

            if ($permission !== null) {
                $declared[] = $permission;
            }
        }

        $this->assertNotSame([], $declared);

        foreach ($declared as $permission) {
            $this->assertInstanceOf(Permission::class, $permission);
            $this->assertNotNull(Permission::tryFrom($permission->value));
        }
    }
}
