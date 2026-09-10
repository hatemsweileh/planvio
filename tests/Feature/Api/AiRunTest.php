<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiTrigger;
use App\Enums\WorkspaceRole;
use App\Jobs\Ai\RunAgentJob;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;

/**
 * The AI surface: a sentence in, a status out.
 *
 * What is asserted here as much as anything is what is *not* reachable. There is no endpoint
 * that runs a tool, so the only way an API caller can cause a mutation is by asking the agent
 * loop for one — and the loop puts every call through the registry, the acting user's own
 * Gate check, and the risk and approval gate (ARCHITECTURE.md §7.1). The run is created in
 * `queued` and executed by a worker, so the endpoint's job ends at "recorded and dispatched".
 */
final class AiRunTest extends ApiTestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->owner = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $this->project = $this->makeProject($this->workspace);

        AiSetting::factory()->enabled()->create(['workspace_id' => $this->workspace->getKey()]);
    }

    #[Test]
    public function it_queues_a_run_and_answers_202(): void
    {
        Queue::fake();

        $response = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/ai/runs', [
            'objective' => 'Summarise what changed on the website project this week.',
            'project_id' => $this->project->getKey(),
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.status', AiRunStatus::Queued->value);
        $response->assertJsonPath('data.trigger', AiTrigger::Api->value);
        $response->assertJsonPath('data.project_id', $this->project->getKey());
        $response->assertJsonPath('data.user_id', $this->owner->getKey());
        $response->assertJsonPath('data.is_finished', false);

        $this->assertIsString($response->json('data.uuid'));
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $response->json('data.uuid'),
        );

        Queue::assertPushed(RunAgentJob::class);
    }

    #[Test]
    public function the_run_is_polled_by_uuid_not_by_id(): void
    {
        Queue::fake();

        $uuid = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/ai/runs', ['objective' => 'Draft the weekly update.'])
            ->json('data.uuid');

        $poll = $this->asToken($this->owner, $this->workspace)->getJson('/api/v1/ai/runs/'.$uuid);

        $poll->assertOk();
        $poll->assertJsonPath('data.uuid', $uuid);
        $poll->assertJsonStructure(['data' => [
            'status', 'steps', 'tool_call_count', 'tokens_in', 'tokens_out', 'is_finished',
        ]]);

        // The numeric id is in the body, but it is not an address: the route only matches a
        // uuid, so a sequential id cannot be walked.
        $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/ai/runs/'.$poll->json('data.id'))
            ->assertStatus(404);
    }

    #[Test]
    public function a_finished_run_reports_its_summary(): void
    {
        $run = AiRun::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->owner->getKey(),
            'status' => AiRunStatus::Succeeded,
            'summary' => 'Created three tasks and one milestone.',
        ]);

        $response = $this->asToken($this->owner, $this->workspace)
            ->getJson('/api/v1/ai/runs/'.$run->uuid);

        $response->assertOk();
        $response->assertJsonPath('data.is_finished', true);
        $response->assertJsonPath('data.summary', 'Created three tasks and one milestone.');
    }

    #[Test]
    public function a_run_carries_its_tool_log_for_the_person_who_started_it(): void
    {
        $run = AiRun::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->member->getKey(),
            'status' => AiRunStatus::Succeeded,
        ]);

        AiToolRun::factory()->create([
            'ai_run_id' => $run->getKey(),
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->member->getKey(),
            'tool' => 'search_tasks',
            'sequence' => 1,
        ]);

        $response = $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/ai/runs/'.$run->uuid);

        $response->assertOk();
        $response->assertJsonPath('data.tool_calls.0.tool', 'search_tasks');

        // The arguments are workspace content and are not part of the contract; the summary
        // is what a caller reads.
        $this->assertArrayNotHasKey('arguments', (array) $response->json('data.tool_calls.0'));
    }

    #[Test]
    public function somebody_elses_run_is_not_readable_without_the_audit_permission(): void
    {
        $run = AiRun::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'user_id' => $this->owner->getKey(),
            'status' => AiRunStatus::Succeeded,
        ]);

        // A plain member holds `ai.use` but not `ai.view_logs`, so somebody else's run is
        // not theirs to read.
        $this->asToken($this->member, $this->workspace)
            ->getJson('/api/v1/ai/runs/'.$run->uuid)
            ->assertStatus(403);
    }

    #[Test]
    public function the_kill_switch_stops_a_run_from_starting(): void
    {
        Queue::fake();

        AiSetting::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->update(['kill_switch_engaged' => true]);

        $response = $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/ai/runs', ['objective' => 'Do something.']);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', 'ai_unavailable');

        Queue::assertNothingPushed();
        $this->assertSame(0, AiRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function the_platform_switch_outranks_the_workspace(): void
    {
        Queue::fake();

        config(['ai.enabled' => false]);

        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/ai/runs', ['objective' => 'Do something.'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ai_unavailable');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function a_guest_cannot_start_a_run(): void
    {
        Queue::fake();

        $guest = $this->makeMember($this->workspace, WorkspaceRole::Guest);

        $this->asToken($guest, $this->workspace)
            ->postJson('/api/v1/ai/runs', ['objective' => 'Do something.'])
            ->assertStatus(403);

        Queue::assertNothingPushed();
    }

    #[Test]
    public function an_objective_is_required(): void
    {
        $this->asToken($this->owner, $this->workspace)
            ->postJson('/api/v1/ai/runs', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');
    }

    #[Test]
    public function asking_for_more_authority_than_the_workspace_allows_is_clamped(): void
    {
        Queue::fake();

        // The workspace is configured for `assistant`; asking for `autonomous` cannot promote
        // it, and the run is recorded with the mode it will actually execute in.
        $response = $this->asToken($this->owner, $this->workspace)->postJson('/api/v1/ai/runs', [
            'objective' => 'Reorganise the backlog.',
            'mode' => AiMode::Autonomous->value,
        ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.mode', AiMode::Assistant->value);
    }

    #[Test]
    public function autonomy_is_clamped_to_copilot_without_the_permission(): void
    {
        Queue::fake();

        AiSetting::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->update(['default_mode' => AiMode::Autonomous->value, 'autonomous_enabled' => true]);

        // A manager may use AI but is not granted `ai.autonomous` by the matrix.
        $manager = $this->makeMember($this->workspace, WorkspaceRole::Manager);

        $this->asToken($manager, $this->workspace)
            ->postJson('/api/v1/ai/runs', ['objective' => 'Reorganise the backlog.'])
            ->assertStatus(202)
            ->assertJsonPath('data.mode', AiMode::Copilot->value);
    }

    #[Test]
    public function there_is_no_endpoint_that_runs_a_tool(): void
    {
        // The absence is the control (ARCHITECTURE.md §7.1): a caller that could name a tool
        // would be a caller who had stepped around the policy and approval gates.
        foreach (['/api/v1/ai/tools', '/api/v1/ai/tools/create_task', '/api/v1/ai/execute'] as $url) {
            $this->asToken($this->owner, $this->workspace)
                ->postJson($url, ['tool' => 'delete_project'])
                ->assertStatus(404);
        }
    }
}
