<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\RunLimits;
use App\Ai\Agent\ToolRegistry;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Policy\ResolvedPolicy;
use App\Ai\Tools\Write\BulkUpdateTasksTool;
use App\Ai\Tools\Write\Support\AssistantMessage;
use App\Enums\AiMemoryScope;
use App\Enums\AiMemorySource;
use App\Enums\AiMode;
use App\Enums\AuthorType;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Enums\WorkspaceRole;
use App\Models\AiMemory;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Tag;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the mutating tools must do, and — more importantly — what they must refuse.
 *
 * Every test here is written against a property from AI_SECURITY.md rather than against an
 * implementation detail, because the implementation is allowed to change and the properties
 * are not:
 *
 *   - a write happens under the acting user's authority, attributed to them, and marked as
 *     the agent's work;
 *   - a permission the acting user does not hold stops the write, and leaves nothing behind;
 *   - an id from another workspace resolves to nothing, indistinguishably from an id that
 *     never existed;
 *   - a repeated call inside one run writes once;
 *   - an ambiguous date produces a question, not a plausible guess.
 *
 * The tools are always fetched through {@see ToolRegistry}, never constructed directly, so
 * these also assert that the registry actually exposes them under the names ARCHITECTURE.md
 * section 7.4 fixes.
 */
final class WriteToolsTest extends TestCase
{
    use RefreshDatabase;

    /* ------------------------------------------------------------------ *
     * create_task — the write path, end to end
     * ------------------------------------------------------------------ */

    #[Test]
    public function create_task_creates_a_task_attributed_to_the_acting_user_and_marked_ai_generated(): void
    {
        $workspace = $this->makeWorkspace(['timezone' => 'UTC']);
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager, ['name' => 'Dana Manager']);
        $project = $this->boardedProject($workspace);

        $result = $this->callTool('create_task', [
            'project_id' => $project->id,
            'title' => 'Recover the campaign timeline',
            'description' => 'Rebuild the plan after the vendor slipped.',
            'priority' => 'high',
            'due_date' => '2026-09-30',
        ], $manager, $workspace, $project);

        $this->assertTrue($result->ok, 'create_task failed: '.$result->summary);

        $task = Task::withoutWorkspaceScope()->firstOrFail();

        $this->assertSame('Recover the campaign timeline', $task->title);
        $this->assertSame((int) $workspace->id, (int) $task->workspace_id);
        $this->assertSame((int) $project->id, (int) $task->project_id);

        // The authority is the human's, and the record says so on both columns the feed and
        // the notifications read.
        $this->assertSame((int) $manager->id, (int) $task->created_by);
        $this->assertSame((int) $manager->id, (int) $task->reporter_id);

        // And it is still visibly the agent's work.
        $this->assertTrue((bool) $task->ai_generated);

        $this->assertSame(Priority::High, $task->priority);
        $this->assertSame('2026-09-30', $task->due_date?->format('Y-m-d'));
        $this->assertSame((int) $task->getKey(), $result->subjectId());
    }

    /* ------------------------------------------------------------------ *
     * Authorization
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_user_without_task_create_is_denied_and_no_task_is_created(): void
    {
        $workspace = $this->makeWorkspace();

        // A guest holds no `task.create` in the capability matrix, in any project.
        $guest = $this->makeMember($workspace, WorkspaceRole::Guest, ['name' => 'Gwen Guest']);
        $project = $this->boardedProject($workspace);

        $result = $this->callTool('create_task', [
            'project_id' => $project->id,
            'title' => 'Should never exist',
        ], $guest, $workspace, $project);

        $this->assertFalse($result->ok);
        $this->assertTrue($result->wasDenied(), 'Expected an authorization denial, got: '.$result->summary);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count(), 'A denied call still wrote a task.');

        // The refusal names the person the agent was acting for, not the agent.
        $this->assertStringContainsString('Gwen Guest', $result->summary);
    }

    #[Test]
    public function assigning_to_someone_outside_the_workspace_fails_without_substituting_anybody(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);
        $task = $this->makeTask($project, ['title' => 'Ship the invoice run']);

        $outsider = User::factory()->create(['name' => 'Olive Outsider']);

        $result = $this->callTool('assign_task', [
            'task_id' => $task->id,
            'assignee_id' => $outsider->id,
        ], $manager, $workspace, $project);

        $this->assertFalse($result->ok);
        $this->assertSame('assignee_not_in_workspace', $result->error);
        $this->assertNull($task->fresh()?->assignee_id, 'The task was quietly assigned to somebody else.');
    }

    /* ------------------------------------------------------------------ *
     * Workspace isolation
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_task_id_from_another_workspace_is_refused(): void
    {
        $home = $this->makeWorkspace(['name' => 'Northwind Home']);
        $owner = $this->makeMember($home, WorkspaceRole::Owner);
        $homeProject = $this->boardedProject($home);

        $foreign = $this->makeWorkspace(['name' => 'Contoso Foreign']);
        $foreignProject = $this->boardedProject($foreign);
        $foreignTask = $this->makeTask($foreignProject, ['title' => 'SECRET foreign task']);

        $result = $this->callTool('update_task', [
            'task_id' => $foreignTask->id,
            'title' => 'Renamed from another tenant',
        ], $owner, $home, $homeProject);

        $this->assertFalse($result->ok);
        $this->assertSame('not_found', $result->error);
        $this->assertSame('SECRET foreign task', $foreignTask->fresh()?->title);

        // The refusal must not confirm that the record exists somewhere else.
        $this->assertStringNotContainsString('SECRET', $result->summary);
        $this->assertStringNotContainsString('Contoso', $result->summary);
    }

    #[Test]
    public function a_project_id_from_another_workspace_cannot_receive_a_task(): void
    {
        $home = $this->makeWorkspace();
        $owner = $this->makeMember($home, WorkspaceRole::Owner);

        $foreign = $this->makeWorkspace();
        $foreignProject = $this->boardedProject($foreign);

        $result = $this->callTool('create_task', [
            'project_id' => $foreignProject->id,
            'title' => 'Planted in the wrong tenant',
        ], $owner, $home, null);

        $this->assertFalse($result->ok);
        $this->assertSame('not_found', $result->error);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
    }

    /* ------------------------------------------------------------------ *
     * Idempotency
     * ------------------------------------------------------------------ */

    #[Test]
    public function calling_create_task_twice_with_identical_args_in_one_run_creates_one_task(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);

        $context = $this->contextFor($manager, $workspace, $project);

        $args = [
            'project_id' => (int) $project->id,
            'title' => 'Draft the retro notes',
        ];

        $first = $this->tool('create_task')->execute($args, $context);
        $second = $this->tool('create_task')->execute($args, $context);

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);

        $this->assertSame(
            1,
            Task::withoutWorkspaceScope()->count(),
            'A repeated identical call inside one run created a duplicate task.',
        );

        // The repeat is reported as one, so the model can see that it did nothing.
        $this->assertTrue($second->data['repeated'] ?? false);
        $this->assertSame($first->summary, $second->summary);
    }

    #[Test]
    public function the_idempotency_key_pins_the_run_the_tool_and_the_arguments(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);

        $context = $this->contextFor($manager, $workspace, $project);
        $other = $this->contextFor($manager, $workspace, $project);

        $tool = $this->tool('create_task');
        $args = ['project_id' => (int) $project->id, 'title' => 'Same'];

        $key = $tool->idempotencyKey($args, $context);

        // Stable whatever order the provider serialised the arguments in.
        $this->assertSame($key, $tool->idempotencyKey(array_reverse($args, true), $context));

        // Different arguments, a different tool and a different run all produce a different key.
        $this->assertNotSame($key, $tool->idempotencyKey(['project_id' => (int) $project->id, 'title' => 'Other'], $context));
        $this->assertNotSame($key, $this->tool('create_subtask')->idempotencyKey($args, $context));
        $this->assertNotSame($key, $tool->idempotencyKey($args, $other));

        $this->assertSame(40, mb_strlen($key), 'The key must fit ai_tool_runs.idempotency_key.');
    }

    #[Test]
    public function a_different_run_may_repeat_the_same_call(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);

        $args = ['project_id' => (int) $project->id, 'title' => 'Weekly report'];

        $this->tool('create_task')->execute($args, $this->contextFor($manager, $workspace, $project));
        $this->tool('create_task')->execute($args, $this->contextFor($manager, $workspace, $project));

        // Idempotency is scoped to one run on purpose: next week's identical request is a
        // real second task, not a duplicate.
        $this->assertSame(2, Task::withoutWorkspaceScope()->count());
    }

    /* ------------------------------------------------------------------ *
     * Dates
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_ambiguous_relative_date_asks_rather_than_guessing(): void
    {
        $workspace = $this->makeWorkspace(['timezone' => 'Pacific/Auckland']);
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);

        $result = $this->callTool('create_task', [
            'project_id' => $project->id,
            'title' => 'Send the renewal quote',
            'due_date' => 'next friday',
        ], $manager, $workspace, $project);

        $this->assertFalse($result->ok);
        $this->assertSame('ambiguous_date', $result->error);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count(), 'An ambiguous date still created a task.');

        // The question has to be answerable: it names the field, the phrase and the zone.
        $this->assertStringContainsString('next friday', $result->summary);
        $this->assertStringContainsString('due_date', $result->summary);
        $this->assertStringContainsString('Pacific/Auckland', $result->summary);
        $this->assertSame('due_date', $result->data['field'] ?? null);
    }

    #[Test]
    public function an_unambiguous_relative_date_resolves_in_the_workspace_timezone(): void
    {
        $workspace = $this->makeWorkspace(['timezone' => 'Pacific/Auckland']);
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);

        $result = $this->callTool('create_task', [
            'project_id' => $project->id,
            'title' => 'Confirm the venue',
            'due_date' => 'tomorrow',
        ], $manager, $workspace, $project);

        $this->assertTrue($result->ok, $result->summary);

        $expected = now('Pacific/Auckland')->addDay()->toDateString();

        $this->assertSame($expected, Task::withoutWorkspaceScope()->firstOrFail()->due_date?->format('Y-m-d'));
    }

    /* ------------------------------------------------------------------ *
     * Argument validation
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_argument_the_tool_never_declared_is_rejected(): void
    {
        $workspace = $this->makeWorkspace();
        $manager = $this->makeMember($workspace, WorkspaceRole::Manager);
        $project = $this->boardedProject($workspace);

        $result = $this->callTool('create_task', [
            'project_id' => $project->id,
            'title' => 'Legitimate title',
            // Neither of these exists on the schema. Silently dropping them would let a
            // model keep probing for one that does.
            'workspace_id' => 9999,
            'ai_generated' => false,
        ], $manager, $workspace, $project);

        $this->assertFalse($result->ok);
        $this->assertSame('invalid_arguments', $result->error);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
    }

    /* ------------------------------------------------------------------ *
     * Attribution
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_ai_comment_is_marked_as_the_assistants_and_cannot_be_edited_as_a_persons(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Member, ['name' => 'Sam Member']);
        $project = $this->boardedProject($workspace);
        $task = $this->makeTask($project);

        $context = $this->contextFor($member, $workspace, $project);

        $result = $this->tool('create_comment')->execute([
            'subject_type' => 'task',
            'subject_id' => (int) $task->id,
            'body' => 'Three of the five subtasks are still open.',
        ], $context);

        $this->assertTrue($result->ok, $result->summary);

        $comment = Comment::withoutWorkspaceScope()->firstOrFail();

        $this->assertSame(AuthorType::Ai, $comment->author_type);
        $this->assertSame($context->runId(), (int) $comment->ai_run_id);
        $this->assertSame((int) $member->id, (int) $comment->user_id, 'The acting user must still be recorded.');

        // Marked as the agent's, and therefore not editable by the person it acted for.
        $this->assertTrue($comment->isFromAi());
        $this->assertFalse($member->can('update', $comment));
    }

    /* ------------------------------------------------------------------ *
     * Bounded blast radius
     * ------------------------------------------------------------------ */

    #[Test]
    public function bulk_update_tasks_caps_the_batch_and_reports_what_it_left_out(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->boardedProject($workspace);

        $limit = BulkUpdateTasksTool::MAX_TASKS;

        $ids = [];

        foreach (range(1, $limit + 5) as $index) {
            $ids[] = (int) $this->makeTask($project, ['priority' => Priority::Low])->id;
        }

        $result = $this->callTool('bulk_update_tasks', [
            'task_ids' => $ids,
            'priority' => 'urgent',
        ], $owner, $workspace, $project);

        $this->assertTrue($result->ok, $result->summary);
        $this->assertSame($limit, $result->data['changed']);
        $this->assertSame(5, $result->data['capped_count']);
        $this->assertStringContainsString('call again for the rest', $result->summary);

        $this->assertSame(
            5,
            Task::withoutWorkspaceScope()->where('priority', Priority::Low->value)->count(),
            'The cap was not actually applied.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Reporting
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_project_report_keeps_recorded_facts_apart_from_analysis(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->boardedProject($workspace);

        $this->makeTask($project);
        $this->makeTask($project);

        $result = $this->callTool('generate_project_report', [
            'project_id' => $project->id,
        ], $owner, $workspace, $project);

        $this->assertTrue($result->ok, $result->summary);

        $this->assertArrayHasKey('recorded', $result->data);
        $this->assertArrayHasKey('analysis', $result->data);

        $recorded = $result->data['recorded'];
        $analysis = $result->data['analysis'];

        // Counts are facts and live only under `recorded`.
        $this->assertSame(2, $recorded['tasks']['total']);
        $this->assertArrayNotHasKey('tasks', $analysis);

        // The verdict is judgement and lives only under `analysis`, with its reasons.
        $this->assertArrayHasKey('health_verdict', $analysis);
        $this->assertArrayHasKey('reasons', $analysis);
        $this->assertArrayNotHasKey('health_verdict', $recorded);

        // A report is not a mutation, and says so.
        $this->assertFalse($this->tool('generate_project_report')->isMutating());
    }

    #[Test]
    public function a_report_answers_freshly_the_second_time_it_is_asked(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->boardedProject($workspace);

        $this->makeTask($project);

        $context = $this->contextFor($owner, $workspace, $project);
        $args = ['project_id' => (int) $project->id];

        $before = $this->tool('generate_project_report')->execute($args, $context);

        $this->makeTask($project);

        $after = $this->tool('generate_project_report')->execute($args, $context);

        // Idempotent replay is for writes. A read that returned a cached answer would break
        // the verify-what-you-changed step the system prompt requires.
        $this->assertSame(1, $before->data['recorded']['tasks']['total']);
        $this->assertSame(2, $after->data['recorded']['tasks']['total']);
        $this->assertArrayNotHasKey('repeated', $after->data);
    }

    /* ------------------------------------------------------------------ *
     * Tags, dependencies, memory, notifications
     * ------------------------------------------------------------------ */

    #[Test]
    public function add_tag_uses_an_existing_tag_and_refuses_to_invent_one(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->boardedProject($workspace);
        $task = $this->makeTask($project);

        $tag = Tag::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Needs Design',
            'slug' => 'needs-design',
        ]);

        // Resolved by the name a person would type, through the slug.
        $applied = $this->callTool('add_tag', [
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'tag' => 'needs design',
        ], $owner, $workspace, $project);

        $this->assertTrue($applied->ok, $applied->summary);
        $this->assertTrue($task->fresh()?->tags()->whereKey($tag->id)->exists());

        $invented = $this->callTool('add_tag', [
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'tag' => 'urgent-ish',
        ], $owner, $workspace, $project);

        $this->assertFalse($invented->ok);
        $this->assertSame('unknown_tag', $invented->error);
        $this->assertSame(1, Tag::withoutWorkspaceScope()->count(), 'The tool created a tag.');
    }

    #[Test]
    public function a_tag_from_another_workspace_cannot_be_attached(): void
    {
        $home = $this->makeWorkspace();
        $owner = $this->makeMember($home, WorkspaceRole::Owner);
        $project = $this->boardedProject($home);
        $task = $this->makeTask($project);

        $foreign = $this->makeWorkspace();
        $foreignTag = Tag::factory()->create(['workspace_id' => $foreign->id, 'name' => 'Foreign']);

        $result = $this->callTool('add_tag', [
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'tag_id' => $foreignTag->id,
        ], $owner, $home, $project);

        $this->assertFalse($result->ok);
        $this->assertSame('not_found', $result->error);
        $this->assertSame(0, $task->fresh()?->tags()->count());
    }

    #[Test]
    public function a_dependency_cycle_is_reported_and_not_retried(): void
    {
        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $project = $this->boardedProject($workspace);

        $first = $this->makeTask($project, ['title' => 'Pour the foundation']);
        $second = $this->makeTask($project, ['title' => 'Frame the walls']);

        $forward = $this->callTool('create_dependency', [
            'task_id' => $second->id,
            'depends_on_task_id' => $first->id,
        ], $owner, $workspace, $project);

        $this->assertTrue($forward->ok, $forward->summary);

        $backward = $this->callTool('create_dependency', [
            'task_id' => $first->id,
            'depends_on_task_id' => $second->id,
        ], $owner, $workspace, $project);

        $this->assertFalse($backward->ok);
        $this->assertSame('dependency_cycle', $backward->error);
        $this->assertSame(1, TaskDependency::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function a_user_scoped_memory_belongs_to_the_acting_user_and_nobody_else(): void
    {
        $workspace = $this->makeWorkspace();
        $member = $this->makeMember($workspace, WorkspaceRole::Member);
        $colleague = $this->makeMember($workspace, WorkspaceRole::Member);
        $project = $this->boardedProject($workspace);

        $result = $this->callTool('create_memory', [
            'key' => 'Preferred Report Day',
            'content' => 'Wants the weekly summary on Friday mornings.',
            'scope' => 'user',
        ], $member, $workspace, $project);

        $this->assertTrue($result->ok, $result->summary);

        $memory = AiMemory::withoutWorkspaceScope()->firstOrFail();

        $this->assertSame((int) $member->id, (int) $memory->user_id);
        $this->assertNotSame((int) $colleague->id, (int) $memory->user_id);
        $this->assertSame((int) $workspace->id, (int) $memory->workspace_id);
        $this->assertSame(AiMemoryScope::User, $memory->scope);
        $this->assertSame(AiMemorySource::Ai, $memory->source);

        // Keys are normalised, so the same idea does not become two rows.
        $this->assertSame('preferred_report_day', $memory->key);
    }

    #[Test]
    public function send_notification_cannot_reach_anybody_outside_the_workspace(): void
    {
        Notification::fake();

        $workspace = $this->makeWorkspace();
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);
        $colleague = $this->makeMember($workspace, WorkspaceRole::Member);
        $outsider = User::factory()->create(['name' => 'Olive Outsider']);

        $result = $this->callTool('send_notification', [
            'recipient_ids' => [(int) $colleague->id, (int) $outsider->id],
            'subject' => 'The migration slipped',
            'message' => 'The vendor moved the cutover to next month.',
        ], $owner, $workspace, null);

        $this->assertTrue($result->ok, $result->summary);
        $this->assertSame([(int) $outsider->id], $result->data['not_members']);

        Notification::assertSentTo($colleague, AssistantMessage::class);
        Notification::assertNotSentTo($outsider, AssistantMessage::class);
    }

    /* ------------------------------------------------------------------ *
     * Registry
     * ------------------------------------------------------------------ */

    #[Test]
    public function every_low_and_medium_tool_is_registered_under_its_contract_name(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $expected = [
            'create_comment', 'create_checklist', 'create_saved_view', 'create_document',
            'update_document', 'generate_project_report', 'send_notification', 'create_memory',
            'create_task', 'update_task', 'assign_task', 'change_task_status', 'create_subtask',
            'create_milestone', 'update_milestone', 'create_dependency', 'add_tag', 'remove_tag',
            'create_project', 'update_project', 'bulk_update_tasks',
        ];

        foreach ($expected as $name) {
            $tool = $registry->resolve($name);

            $this->assertInstanceOf(AiTool::class, $tool, "The registry does not know {$name}.");
            $this->assertSame($name, $tool->name());
            $this->assertSame('object', $tool->parameters()['type'] ?? null);
            $this->assertFalse(
                $tool->parameters()['additionalProperties'] ?? true,
                "{$name} accepts undeclared arguments.",
            );
        }

        // A name the registry does not know produces nothing — never a class.
        $this->assertNull($registry->resolve('drop_all_tasks'));
        $this->assertNull($registry->resolve('App\\Ai\\Tools\\Write\\CreateTaskTool'));
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $args
     */
    private function callTool(
        string $tool,
        array $args,
        User $user,
        Workspace $workspace,
        ?Project $project = null,
    ): ToolResult {
        return $this->tool($tool)->execute($args, $this->contextFor($user, $workspace, $project));
    }

    private function tool(string $name): AiTool
    {
        $tool = $this->app->make(ToolRegistry::class)->resolve($name);

        $this->assertInstanceOf(AiTool::class, $tool, "No tool named {$name} is registered.");

        return $tool;
    }

    private function contextFor(User $user, Workspace $workspace, ?Project $project): AgentContext
    {
        $settings = AiSetting::factory()->make(['workspace_id' => $workspace->id]);

        $run = AiRun::withoutWorkspaceScope()->create([
            'workspace_id' => $workspace->id,
            'project_id' => $project?->id,
            'user_id' => $user->id,
            'trigger' => 'chat',
            'mode' => AiMode::Autonomous,
            'status' => 'running',
        ]);

        return new AgentContext(
            user: $user,
            workspace: $workspace,
            project: $project,
            task: null,
            conversation: null,
            run: $run,
            mode: AiMode::Autonomous,
            policy: ResolvedPolicy::resolve($settings),
            limits: RunLimits::fromSettings($settings),
            timezone: (string) $workspace->timezone,
        );
    }

    /**
     * A project with a real board, because a task has nowhere to land without one.
     */
    private function boardedProject(Workspace $workspace, array $attributes = []): Project
    {
        $project = $this->makeProject($workspace, [], $attributes);

        TaskStatus::factory()
            ->for($project)
            ->inCategory(StatusCategory::Todo)
            ->asDefault()
            ->create(['name' => 'To Do']);

        TaskStatus::factory()
            ->for($project)
            ->inCategory(StatusCategory::InProgress)
            ->create(['name' => 'In Progress']);

        TaskStatus::factory()
            ->for($project)
            ->inCategory(StatusCategory::Done)
            ->create(['name' => 'Done']);

        return $project->fresh();
    }
}
