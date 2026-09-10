<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\AiMessageRole;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiToolRisk;
use App\Enums\AiTrigger;
use App\Enums\ToolRunStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\Ai\ResumeAgentRunJob;
use App\Jobs\Ai\RunAgentJob;
use App\Livewire\App\Ai\Approvals;
use App\Livewire\App\Ai\Index as AiWorkspace;
use App\Livewire\App\Ai\Panel;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The AI product surface: the screens that make the agent layer reachable, and the four
 * properties they must never lose.
 *
 * **A message starts a real run.** Not a redirect, not a stub: a conversation, a user
 * message, an `ai_runs` row in `queued`, and a job on the `ai` queue. Planvio has no
 * persistent worker, so the surface's whole contract is "recorded, dispatched, polled"
 * (docs/QUEUE.md).
 *
 * **The gate is asked before anything is written.** A workspace with AI switched off shows
 * the disabled state and creates nothing at all — no orphan conversation, no run nobody can
 * continue.
 *
 * **Permission is a permission.** `ai.use` is what the route, the sidebar and the component
 * all require, and without it the screen is a 403 rather than an empty one.
 *
 * **The approval card is the request.** It renders the parked call's arguments verbatim,
 * because those are the bytes the runner replays; see
 * tests/Feature/Security/AiApprovalFidelityTest.php for the storage half of the same rule.
 */
final class AiSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['timezone' => 'UTC']);
        $this->actor = $this->makeMember($this->workspace, WorkspaceRole::Owner);
    }

    /* ------------------------------------------------------------------ *
     * Starting a run
     * ------------------------------------------------------------------ */

    #[Test]
    public function sending_a_message_creates_a_conversation_a_message_and_a_run_and_dispatches_the_job(): void
    {
        Queue::fake();
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        Livewire::test(AiWorkspace::class, ['workspace' => $this->workspace])
            ->set('draft', 'What is at risk this week?')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('draft', '');

        $conversation = AiConversation::withoutWorkspaceScope()->sole();

        $this->assertSame($this->workspace->id, $conversation->workspace_id);
        $this->assertSame($this->actor->id, $conversation->user_id);
        $this->assertSame('What is at risk this week?', $conversation->title);
        $this->assertSame(1, (int) $conversation->message_count);

        $message = AiMessage::query()->sole();

        $this->assertSame(AiMessageRole::User, $message->role);
        $this->assertSame('What is at risk this week?', $message->content);
        $this->assertSame($conversation->id, $message->ai_conversation_id);

        $run = AiRun::withoutWorkspaceScope()->sole();

        $this->assertSame(AiTrigger::Chat, $run->trigger);
        $this->assertSame(AiRunStatus::Queued, $run->status);
        $this->assertSame($conversation->id, $run->ai_conversation_id);
        $this->assertSame($this->actor->id, $run->user_id);
        $this->assertSame('What is at risk this week?', $run->objective);
        $this->assertSame($message->ai_run_id, $run->id);

        Queue::assertPushed(
            RunAgentJob::class,
            static fn (RunAgentJob $job): bool => $job->queue === config('ai.queue.name'),
        );
    }

    /**
     * The drawer is the same machinery in a narrower column. It used to hand the question to
     * the full screen with a redirect, which threw away the page the person was looking at —
     * the one thing they wanted the assistant to have.
     */
    #[Test]
    public function the_drawer_runs_the_agent_rather_than_redirecting(): void
    {
        Queue::fake();
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        Livewire::test(Panel::class)
            ->call('begin', null, null, 'Summarise the week', '')
            ->assertNoRedirect()
            ->assertSet('draft', 'Summarise the week')
            ->call('send')
            ->assertNoRedirect();

        $this->assertSame(1, AiRun::withoutWorkspaceScope()->count());
        $this->assertSame(1, AiConversation::withoutWorkspaceScope()->count());

        Queue::assertPushed(RunAgentJob::class);
    }

    /**
     * The dashboard's insights card, the create menu and the empty project list all open the
     * drawer with an *intent* rather than a sentence. An intent is sent rather than typed,
     * because the button's own label already said what it would do — and the review intent
     * has to stay read-only, since that card is an analysis sitting beside measured numbers.
     */
    #[Test]
    public function an_intent_from_the_dashboard_starts_a_real_read_only_run(): void
    {
        Queue::fake();
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        Livewire::test(Panel::class)
            ->call('begin', null, null, '', 'review')
            ->assertHasNoErrors();

        $run = AiRun::withoutWorkspaceScope()->sole();

        $this->assertSame(AiTrigger::Chat, $run->trigger);
        $this->assertSame(AiRunStatus::Queued, $run->status);
        $this->assertStringContainsString('Read only', (string) $run->objective);

        Queue::assertPushed(RunAgentJob::class);
    }

    /* ------------------------------------------------------------------ *
     * Refusals
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_user_without_ai_use_is_refused_the_ai_workspace(): void
    {
        $this->enableAi();

        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        $this->actingAs($guest)
            ->get(route('app.ai', $this->workspace))
            ->assertForbidden();
    }

    /**
     * The whole page, through the shell, with the drawer mounted alongside it. A component
     * test cannot catch a layout that throws or a partial that reads a relation nobody
     * loaded — and `Model::preventLazyLoading()` turns the second of those into a 500.
     */
    #[Test]
    public function the_ai_workspace_and_the_project_tab_render_end_to_end(): void
    {
        $this->enableAi();

        $project = $this->makeProject($this->workspace, [], ['name' => 'Website Redesign']);

        $this->actingInWorkspace($this->actor, $this->workspace);

        $this->get(route('app.ai', $this->workspace))
            ->assertOk()
            ->assertSee(__('New conversation'))
            ->assertSee(__('Ask Planvio AI'))
            ->assertSee(AiMode::Copilot->label());

        $this->get(route('app.projects.ai', [$this->workspace, $project]))
            ->assertOk()
            ->assertSee('Website Redesign');
    }

    #[Test]
    public function a_workspace_with_ai_disabled_shows_the_disabled_state_and_creates_nothing(): void
    {
        Queue::fake();

        AiSetting::factory()->create([
            'workspace_id' => $this->workspace->id,
            'is_enabled' => false,
            'ai_provider_id' => AiProvider::factory()->active()->create()->id,
        ]);

        $this->actingInWorkspace($this->actor, $this->workspace);

        Livewire::test(AiWorkspace::class, ['workspace' => $this->workspace])
            ->assertSee(__('ai.gate.disabled_for_workspace'))
            ->set('draft', 'Do something useful.')
            ->call('send')
            ->assertSet('draft', 'Do something useful.');

        $this->assertSame(0, AiConversation::withoutWorkspaceScope()->count());
        $this->assertSame(0, AiMessage::query()->count());
        $this->assertSame(0, AiRun::withoutWorkspaceScope()->count());

        Queue::assertNothingPushed();
    }

    /* ------------------------------------------------------------------ *
     * Approvals
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_approval_card_renders_the_verbatim_arguments(): void
    {
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        // Long enough to be cut by the audit storage cap, and carrying a string the redactor
        // would rewrite. The card must show neither treatment: this is what will execute.
        $description = 'Rotate the key sk-planvio-live-9f2a7c4e18b350da before launch. '
            .str_repeat('Then confirm the campaign landing page copy with legal. ', 20);

        $this->parkedCall('create_task', AiToolRisk::Medium, [
            'title' => 'Prepare the launch checklist',
            'description' => $description,
        ]);

        Livewire::test(Approvals::class, ['workspace' => $this->workspace])
            ->assertSee(__('Exactly what will run'))
            ->assertSee('create_task')
            ->assertSee('Prepare the launch checklist')
            ->assertSee($description);
    }

    /**
     * PlanvioUrl resolves `app.ai.approvals` and `app.ai.runs.show` by name when it builds
     * the "an action needs your approval" link. Without those routes registered it falls
     * back to a raw path, and the most important notification in the product lands on a 404.
     */
    #[Test]
    public function the_links_an_approval_notification_builds_actually_resolve(): void
    {
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        $toolRun = $this->parkedCall('create_task', AiToolRisk::Medium, ['title' => 'Ship it']);
        $run = $this->runOf($toolRun);

        $this->get(PlanvioUrl::aiApprovals($this->workspace))
            ->assertOk()
            ->assertSee(__('Waiting for a decision'))
            ->assertSee('create_task');

        $this->get(PlanvioUrl::aiRun($this->workspace, (string) $run->uuid))
            ->assertOk()
            ->assertSee(__('One run'))
            ->assertSee('Do the thing.');
    }

    /**
     * The audit permission gates the run history, wherever it is reached from.
     */
    #[Test]
    public function a_member_without_ai_view_logs_cannot_open_a_run(): void
    {
        $this->enableAi();

        $member = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $toolRun = $this->parkedCall('create_task', AiToolRisk::Medium, ['title' => 'Ship it']);

        $this->actingAs($member)
            ->get(PlanvioUrl::aiRun($this->workspace, (string) $this->runOf($toolRun)->uuid))
            ->assertForbidden();
    }

    #[Test]
    public function approving_dispatches_the_resume_job(): void
    {
        Queue::fake();
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        $toolRun = $this->parkedCall('create_task', AiToolRisk::Medium, ['title' => 'Ship it']);

        Livewire::test(Approvals::class, ['workspace' => $this->workspace])
            ->call('approveToolRun', $toolRun->id)
            ->assertHasNoErrors();

        $decided = $toolRun->fresh();

        $this->assertSame(ToolRunStatus::Approved, $decided->status);
        $this->assertSame($this->actor->id, $decided->approved_by);
        $this->assertSame(AiRunStatus::Queued, $this->runOf($decided)->status);

        Queue::assertPushed(
            ResumeAgentRunJob::class,
            static fn (ResumeAgentRunJob $job): bool => $job->queue === config('ai.queue.name'),
        );
    }

    /**
     * The one asymmetry in the queue: rejecting is a click, approving a destructive call is
     * not. Without the typed name nothing is decided and nothing is dispatched.
     */
    #[Test]
    public function approving_a_destructive_call_requires_the_tool_name_to_be_typed(): void
    {
        Queue::fake();
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        $toolRun = $this->parkedCall('delete_project', AiToolRisk::Destructive, ['project_id' => 7]);

        $component = Livewire::test(Approvals::class, ['workspace' => $this->workspace])
            ->call('approveToolRun', $toolRun->id)
            ->assertHasErrors('approval.'.$toolRun->id);

        $this->assertSame(ToolRunStatus::PendingApproval, $toolRun->fresh()->status);
        Queue::assertNotPushed(ResumeAgentRunJob::class);

        $component->set('approvalConfirmations.'.$toolRun->id, 'delete_project')
            ->call('approveToolRun', $toolRun->id)
            ->assertHasNoErrors();

        $this->assertSame(ToolRunStatus::Approved, $toolRun->fresh()->status);
        Queue::assertPushed(ResumeAgentRunJob::class);
    }

    #[Test]
    public function rejecting_is_one_click_and_stops_the_run(): void
    {
        Queue::fake();
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        $toolRun = $this->parkedCall('delete_project', AiToolRisk::Destructive, ['project_id' => 7]);

        Livewire::test(Approvals::class, ['workspace' => $this->workspace])
            ->set('rejectionReasons.'.$toolRun->id, 'Not this quarter.')
            ->call('rejectToolRun', $toolRun->id)
            ->assertHasNoErrors();

        $decided = $toolRun->fresh();

        $this->assertSame(ToolRunStatus::Rejected, $decided->status);
        $this->assertSame('Not this quarter.', $decided->rejected_reason);
        $this->assertSame(AiRunStatus::Cancelled, $this->runOf($decided)->status);

        Queue::assertNotPushed(ResumeAgentRunJob::class);
    }

    /* ------------------------------------------------------------------ *
     * The trace
     * ------------------------------------------------------------------ */

    /**
     * The transcript is not the agent's own account of itself. Every call it made is drawn
     * from `ai_tool_runs`, with the tool, a readable line of arguments and the result.
     */
    #[Test]
    public function the_conversation_renders_the_tool_trace_beside_the_assistant_text(): void
    {
        $this->enableAi();
        $this->actingInWorkspace($this->actor, $this->workspace);

        $conversation = AiConversation::factory()->forUser($this->actor)->create([
            'workspace_id' => $this->workspace->id,
            'mode' => AiMode::Copilot,
        ]);

        $run = AiRun::factory()->forConversation($conversation)->create([
            'user_id' => $this->actor->id,
            'status' => AiRunStatus::Succeeded,
        ]);

        AiMessage::factory()->forConversation($conversation)->forRun($run)->create([
            'role' => AiMessageRole::User,
            'content' => 'Which tasks are overdue?',
        ]);

        AiMessage::factory()->forConversation($conversation)->forRun($run)->create([
            'role' => AiMessageRole::Assistant,
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'name' => 'search_tasks',
                'arguments' => ['status' => 'overdue', 'limit' => 10],
            ]],
        ]);

        AiToolRun::factory()->forRun($run)->create([
            'tool' => 'search_tasks',
            'risk' => AiToolRisk::Read,
            'arguments' => ['status' => 'overdue', 'limit' => 10],
            'result_summary' => 'Found 3 overdue tasks.',
            'status' => ToolRunStatus::Succeeded,
            'duration_ms' => 84,
            'sequence' => 1,
        ]);

        AiMessage::factory()->forConversation($conversation)->forRun($run)->create([
            'role' => AiMessageRole::Assistant,
            'content' => 'Three tasks are overdue.',
        ]);

        Livewire::test(AiWorkspace::class, ['workspace' => $this->workspace])
            ->call('openConversation', $conversation->id)
            ->assertSee('Which tasks are overdue?')
            ->assertSee('Three tasks are overdue.')
            ->assertSee('search_tasks')
            ->assertSee('status: overdue')
            ->assertSee('Found 3 overdue tasks.')
            ->assertSee('84 ms');
    }

    /* ------------------------------------------------------------------ *
     * Fixtures
     * ------------------------------------------------------------------ */

    private function enableAi(): void
    {
        AiSetting::factory()->create([
            'workspace_id' => $this->workspace->id,
            'is_enabled' => true,
            'default_mode' => AiMode::Copilot,
            'ai_provider_id' => AiProvider::factory()->active()->create([
                'base_url' => 'https://api.openai.com/v1',
                'model' => 'gpt-4o-mini',
            ])->id,
        ]);
    }

    /**
     * The parent run, re-read. Read explicitly rather than through the relation because
     * `Model::preventLazyLoading()` is on outside production and a test should not be the
     * one place that quietly relies on a lazy load.
     */
    private function runOf(AiToolRun $toolRun): AiRun
    {
        return AiRun::withoutWorkspaceScope()->findOrFail($toolRun->ai_run_id);
    }

    /**
     * A run parked on a human decision, holding its arguments verbatim the way the runner
     * writes them.
     *
     * @param array<string, mixed> $arguments
     */
    private function parkedCall(string $tool, AiToolRisk $risk, array $arguments): AiToolRun
    {
        $run = AiRun::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->actor->id,
            'mode' => AiMode::Copilot,
            'status' => AiRunStatus::AwaitingApproval,
            'objective' => 'Do the thing.',
        ]);

        return AiToolRun::factory()->forRun($run)->create([
            'tool' => $tool,
            'risk' => $risk,
            'arguments' => $arguments,
            'status' => ToolRunStatus::PendingApproval,
            'approval_required' => true,
            'result_summary' => 'Awaiting approval — '.$tool.' would affect: tasks: 47',
            'duration_ms' => null,
            'sequence' => 1,
        ]);
    }
}
