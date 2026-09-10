<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Ai\Agent\AgentRunner;
use App\Ai\Approvals\ApprovalService;
use App\Ai\Support\Redactor;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\StatusCategory;
use App\Enums\ToolRunStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What a person approves must be what actually runs.
 *
 * Every ai_tool_runs row stores redacted arguments, which is right for an audit trail and
 * wrong for a row that is still waiting on a human: the approval card renders from that
 * column and the runner replays from it. Redacting first means the approver reads one thing
 * and the system executes another — a governance failure, and, for any description longer
 * than the storage cap or containing a credential-shaped string, silent corruption of the
 * record they authorised.
 *
 * So a parked call holds its arguments verbatim, and redaction happens on the way to any
 * terminal status. These tests pin both halves; the second half is the one that is easy to
 * lose, because nothing else ever touches a rejected row again.
 */
final class AiApprovalFidelityTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    private Project $project;

    /**
     * Long enough to be truncated by the storage cap, and carrying a string the shape
     * scrubber will rewrite. Both would corrupt the approved task.
     */
    private function longDescription(): string
    {
        return 'Rotate the key sk-planvio-live-9f2a7c4e18b350da before launch. '
            .str_repeat('Then confirm the campaign landing page copy with legal. ', 60);
    }

    #[Test]
    public function a_call_waiting_for_approval_keeps_its_arguments_verbatim(): void
    {
        $run = $this->stage(AiMode::Copilot);
        $description = $this->longDescription();

        $this->fakeProvider($this->toolCall('create_task', [
            'project_id' => $this->project->id,
            'title' => 'Prepare the launch checklist',
            'description' => $description,
        ]));

        $run = $this->runAgent($run, 'Create the launch checklist task.');

        $this->assertSame(AiRunStatus::AwaitingApproval, $run->status);

        $parked = AiToolRun::withoutWorkspaceScope()->where('ai_run_id', $run->id)->firstOrFail();

        $this->assertSame(ToolRunStatus::PendingApproval, $parked->status);
        $this->assertSame(
            $description,
            $parked->arguments['description'] ?? null,
            'The approval card would show, and the runner would replay, a rewritten description.',
        );
    }

    #[Test]
    public function approving_creates_the_record_the_approver_actually_saw(): void
    {
        $run = $this->stage(AiMode::Copilot);
        $description = $this->longDescription();

        $this->fakeProvider($this->toolCall('create_task', [
            'project_id' => $this->project->id,
            'title' => 'Prepare the launch checklist',
            'description' => $description,
        ]));

        $run = $this->runAgent($run, 'Create the launch checklist task.');
        $parked = AiToolRun::withoutWorkspaceScope()->where('ai_run_id', $run->id)->firstOrFail();

        // The card the approver reads is the stored arguments.
        $shownToApprover = $parked->arguments['description'];

        $this->fakeProvider($this->assistantText('Done.'));
        $this->app->make(ApprovalService::class)->approve($parked, $this->actor);

        $resumed = $run->fresh();
        $this->app->make(AgentRunner::class)->run(
            $this->app->make(AgentRunner::class)->contextFor($resumed),
            'Create the launch checklist task.',
        );

        $task = Task::withoutWorkspaceScope()
            ->where('project_id', $this->project->id)
            ->where('title', 'Prepare the launch checklist')
            ->first();

        $this->assertNotNull($task, 'The approved call did not execute.');

        // CreateTask trims its input, which is correct, so compare on trimmed values:
        // what is under test is that nothing was redacted or truncated on the way through.
        $this->assertSame(
            trim($shownToApprover),
            trim((string) $task->description),
            'The created task differs from what the approver was shown.',
        );
        $this->assertStringNotContainsString(
            Redactor::REDACTED,
            (string) $task->description,
            'The approved description was rewritten by the audit redactor.',
        );
        $this->assertStringNotContainsString(
            'truncated',
            (string) $task->description,
            'The approved description was cut to the audit storage cap.',
        );
    }

    #[Test]
    public function a_completed_call_is_redacted_in_the_audit_trail(): void
    {
        $run = $this->stage(AiMode::Copilot);

        $this->fakeProvider($this->toolCall('create_task', [
            'project_id' => $this->project->id,
            'title' => 'Prepare the launch checklist',
            'description' => $this->longDescription(),
        ]));

        $run = $this->runAgent($run, 'Create the launch checklist task.');
        $parked = AiToolRun::withoutWorkspaceScope()->where('ai_run_id', $run->id)->firstOrFail();

        $this->fakeProvider($this->assistantText('Done.'));
        $this->app->make(ApprovalService::class)->approve($parked, $this->actor);

        $resumed = $run->fresh();
        $this->app->make(AgentRunner::class)->run(
            $this->app->make(AgentRunner::class)->contextFor($resumed),
            'Create the launch checklist task.',
        );

        $stored = $parked->fresh();

        $this->assertSame(ToolRunStatus::Succeeded, $stored->status);
        $this->assertStringContainsString(
            Redactor::REDACTED,
            (string) ($stored->arguments['description'] ?? ''),
            'A completed call must not keep a credential-shaped string in the audit row.',
        );
    }

    #[Test]
    public function a_rejected_call_is_redacted_too(): void
    {
        $run = $this->stage(AiMode::Copilot);

        $this->fakeProvider($this->toolCall('create_task', [
            'project_id' => $this->project->id,
            'title' => 'Prepare the launch checklist',
            'description' => $this->longDescription(),
        ]));

        $run = $this->runAgent($run, 'Create the launch checklist task.');
        $parked = AiToolRun::withoutWorkspaceScope()->where('ai_run_id', $run->id)->firstOrFail();

        $this->app->make(ApprovalService::class)->reject($parked, $this->actor, 'Not now.');

        $stored = $parked->fresh();

        $this->assertSame(ToolRunStatus::Rejected, $stored->status);
        $this->assertStringContainsString(
            Redactor::REDACTED,
            (string) ($stored->arguments['description'] ?? ''),
            'Nothing else ever touches a rejected row, so it would keep the raw arguments forever.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Harness — mirrors tests/Feature/Ai/AgentRunnerTest.php
     * ------------------------------------------------------------------ */

    private function stage(AiMode $mode): AiRun
    {
        $this->workspace = $this->makeWorkspace(['timezone' => 'UTC']);
        $this->actor = $this->makeMember($this->workspace, WorkspaceRole::Owner);

        $provider = AiProvider::factory()->active()->create([
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
        ]);

        AiSetting::factory()->create([
            'workspace_id' => $this->workspace->id,
            'is_enabled' => true,
            'ai_provider_id' => $provider->id,
            'default_mode' => $mode,
            'autonomous_enabled' => $mode === AiMode::Autonomous,
        ]);

        $this->project = $this->makeProject($this->workspace, [], ['key' => 'WEB']);

        // A project with no board column cannot hold a task, and create_task correctly
        // refuses rather than inventing one — so the fixture needs real statuses or the
        // approved call fails for a reason that has nothing to do with what is under test.
        TaskStatus::factory()->for($this->project)->inCategory(StatusCategory::Todo)->asDefault()->create(['name' => 'To Do']);
        TaskStatus::factory()->for($this->project)->inCategory(StatusCategory::Done)->create(['name' => 'Done']);

        $this->project = $this->project->fresh();

        return AiRun::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->actor->id,
            'trigger' => 'chat',
            'mode' => $mode,
            'status' => AiRunStatus::Queued,
        ]);
    }

    private function runAgent(AiRun $run, string $objective): AiRun
    {
        $runner = $this->app->make(AgentRunner::class);

        return $runner->run($runner->contextFor($run), $objective)->fresh() ?? $run;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function fakeProvider(array $body): void
    {
        Http::fake(['api.openai.com/*' => static fn (): mixed => Http::response($body)]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments): array
    {
        return [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'tool_calls',
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => $name,
                            'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantText(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'index' => 0,
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
        ];
    }
}
