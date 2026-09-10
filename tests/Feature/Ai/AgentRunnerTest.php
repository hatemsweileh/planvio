<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agent\AgentRunner;
use App\Enums\AiMode;
use App\Enums\AiRunStatus;
use App\Enums\AiToolRisk;
use App\Enums\StatusCategory;
use App\Enums\ToolRunStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\Ai\ResumeAgentRunJob;
use App\Jobs\Ai\RunAgentJob;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\AiToolRun;
use App\Models\AiUsageDaily;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Ai\AiApprovalRequired;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * The agent loop, driven against a faked provider.
 *
 * Every test here asserts a property the loop must hold whatever the model says, because the
 * model is the one part of this system nobody controls:
 *
 *   - work the model asked for is actually done, once, and recorded;
 *   - a name the registry does not know is rejected rather than resolved;
 *   - a limit stops the run and the report says so plainly instead of implying completion;
 *   - a tool that failed is reported as failed, and the run does not claim success;
 *   - a call that needs a human stops the run dead, with nothing after it attempted.
 *
 * The provider is always {@see Http::fake()}d. `Tests\TestCase` turns on
 * `preventStrayRequests()`, so a run that reached a real endpoint would fail loudly here.
 */
final class AgentRunnerTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $actor;

    private Project $project;

    private AiProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ai.enabled' => true]);

        Notification::fake();
    }

    /* ------------------------------------------------------------------ *
     * A single tool, end to end
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_single_tool_run_creates_the_record_and_finishes_succeeded(): void
    {
        $run = $this->stage();

        $this->fakeProvider([
            $this->toolCall('create_task', [
                'project_id' => (int) $this->project->id,
                'title' => 'Recover the campaign timeline',
                'priority' => 'high',
            ]),
            $this->assistantText('Created WEB-1 and set it to high priority.'),
        ]);

        $finished = $this->runAgent($run, 'Create a high priority task to recover the campaign timeline.');

        $this->assertSame(AiRunStatus::Succeeded, $finished->status);

        $task = Task::withoutWorkspaceScope()->firstOrFail();
        $this->assertSame('Recover the campaign timeline', $task->title);
        $this->assertSame((int) $this->actor->id, (int) $task->created_by);

        $toolRuns = $this->toolRuns($finished);
        $this->assertCount(1, $toolRuns);

        $record = $toolRuns[0];
        $this->assertSame('create_task', $record->tool);
        $this->assertSame(ToolRunStatus::Succeeded, $record->status);
        $this->assertSame(AiToolRisk::Medium, $record->risk);
        $this->assertSame(1, (int) $record->sequence);
        $this->assertSame((int) $task->id, (int) $record->subject_id);
        $this->assertSame((int) $this->actor->id, (int) $record->user_id);
        $this->assertNotNull($record->idempotency_key);

        // The verifier read the record back and said so on the row a human reads.
        $this->assertStringContainsString('Verification (verified)', (string) $record->result_summary);

        // Two provider calls: the one that proposed the tool, and the one that saw its result.
        $this->assertCount(2, Http::recorded());

        $this->assertSame(1, (int) $finished->tool_call_count);
        $this->assertSame(2, (int) $finished->steps);
        $this->assertSame(0, (int) $finished->error_count);
        $this->assertGreaterThan(0, (int) $finished->tokens_in);

        $usage = AiUsageDaily::query()->firstOrFail();
        $this->assertSame(1, (int) $usage->runs);
        $this->assertSame(1, (int) $usage->tool_calls);
        $this->assertSame((int) $finished->tokens_in, (int) $usage->tokens_in);
        $this->assertSame((int) $finished->tokens_out, (int) $usage->tokens_out);
        $this->assertSame((int) $this->workspace->id, (int) $usage->workspace_id);
    }

    /* ------------------------------------------------------------------ *
     * Several tools in sequence
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_multi_step_run_chains_three_tools_in_order(): void
    {
        $run = $this->stage();
        $task = $this->makeTask($this->project, ['title' => 'Ship the invoice run']);
        $colleague = $this->makeMember($this->workspace, WorkspaceRole::Member, ['name' => 'Sam Member']);

        $this->fakeProvider([
            $this->toolCall('assign_task', [
                'task_id' => (int) $task->id,
                'assignee_id' => (int) $colleague->id,
            ], 'call_a'),
            $this->toolCall('change_task_status', [
                'task_id' => (int) $task->id,
                'status' => 'In Progress',
            ], 'call_b'),
            $this->toolCall('create_comment', [
                'subject_type' => 'task',
                'subject_id' => (int) $task->id,
                'body' => 'Picked this up and moved it into progress.',
            ], 'call_c'),
            $this->assistantText('Assigned it to Sam, moved it to In Progress and left a note.'),
        ]);

        $finished = $this->runAgent($run, 'Assign the invoice run to Sam, start it, and leave a note.');

        $this->assertSame(AiRunStatus::Succeeded, $finished->status);
        $this->assertSame(3, (int) $finished->tool_call_count);
        $this->assertSame(4, (int) $finished->steps);

        $this->assertSame(
            ['assign_task', 'change_task_status', 'create_comment'],
            array_map(static fn (AiToolRun $record): string => (string) $record->tool, $this->toolRuns($finished)),
        );

        $this->assertSame([1, 2, 3], array_map(
            static fn (AiToolRun $record): int => (int) $record->sequence,
            $this->toolRuns($finished),
        ));

        $fresh = $task->fresh();
        $this->assertSame((int) $colleague->id, (int) $fresh?->assignee_id);
        $this->assertSame('In Progress', $fresh?->status?->name);
        $this->assertSame(1, Comment::withoutWorkspaceScope()->count());

        // Each tool result travelled back to the model before the next call was proposed.
        $lastRequest = $this->requestBodies()[3];
        $this->assertSame(3, $this->countMessagesOfRole($lastRequest, 'tool'));
    }

    /* ------------------------------------------------------------------ *
     * A name the registry does not know
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_unknown_tool_name_is_rejected_reported_and_never_executed(): void
    {
        $run = $this->stage();

        $this->fakeProvider([
            $this->toolCall('delete_everything', ['scope' => 'workspace']),
            $this->assistantText('There is no such tool, so I did nothing.'),
        ]);

        $finished = $this->runAgent($run, 'Delete everything.');

        // A failed call means the run is not a success, and it must not be reported as one.
        $this->assertSame(AiRunStatus::Partial, $finished->status);
        $this->assertSame(1, (int) $finished->error_count);

        $toolRuns = $this->toolRuns($finished);
        $this->assertCount(1, $toolRuns);

        $record = $toolRuns[0];
        $this->assertSame('delete_everything', $record->tool);
        $this->assertSame(ToolRunStatus::Failed, $record->status);
        $this->assertSame('unknown_tool', $record->error);
        // Nothing was touched, so nothing was risked — and no idempotency key was burned on a
        // name that will never resolve.
        $this->assertSame(AiToolRisk::Read, $record->risk);
        $this->assertNull($record->idempotency_key);

        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
        $this->assertSame(0, Comment::withoutWorkspaceScope()->count());

        // The model was told, in a tool message, rather than left to guess.
        $second = $this->requestBodies()[1];
        $this->assertSame(1, $this->countMessagesOfRole($second, 'tool'));
        $this->assertStringContainsString('unknown_tool', $this->messagesOfRole($second, 'tool')[0]['content']);
        $this->assertStringContainsString('delete_everything', $this->messagesOfRole($second, 'tool')[0]['content']);
    }

    /* ------------------------------------------------------------------ *
     * Limits
     * ------------------------------------------------------------------ */

    #[Test]
    public function max_tool_calls_stops_the_run_and_the_summary_says_what_remains(): void
    {
        $run = $this->stage(overrides: ['max_tool_calls_per_run' => 2]);

        // The model never stops asking. The loop has to.
        $this->fakeAlways($this->toolCall('search_tasks', ['query' => 'overdue']));

        $finished = $this->runAgent($run, 'Find everything that is overdue and keep looking.');

        $this->assertSame(AiRunStatus::LimitReached, $finished->status);
        $this->assertSame(2, (int) $finished->tool_call_count);
        $this->assertCount(2, $this->toolRuns($finished));

        $summary = (string) $finished->summary;
        $this->assertStringContainsString('ceiling of 2 tool calls', $summary);
        $this->assertStringContainsString('not attempted', $summary);
        $this->assertStringContainsString('search_tasks', $summary);
    }

    #[Test]
    public function calling_one_tool_six_times_with_the_same_arguments_trips_the_repeat_ceiling(): void
    {
        $this->assertSame(5, (int) config('ai.limits.max_same_tool_repeats'));

        $run = $this->stage();

        $this->fakeAlways($this->toolCall('search_tasks', ['query' => 'overdue']));

        $finished = $this->runAgent($run, 'Look for overdue work.');

        $this->assertSame(AiRunStatus::LimitReached, $finished->status);

        // Five got through; the sixth was refused before it ran.
        $this->assertSame(5, (int) $finished->tool_call_count);
        $this->assertCount(5, $this->toolRuns($finished));

        $this->assertStringContainsString('repeat ceiling', (string) $finished->summary);
        $this->assertStringContainsString('not attempted', (string) $finished->summary);
    }

    /* ------------------------------------------------------------------ *
     * Honest failure
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_failing_tool_ends_the_run_partial_and_is_reported_rather_than_hidden(): void
    {
        $run = $this->stage();

        $this->fakeProvider([
            $this->toolCall('create_task', [
                'project_id' => 999999,
                'title' => 'A task in a project that does not exist',
            ]),
            $this->assistantText('I could not find that project, so I created nothing.'),
        ]);

        $finished = $this->runAgent($run, 'Add a task to project 999999.');

        $this->assertSame(AiRunStatus::Partial, $finished->status);
        $this->assertNotSame(AiRunStatus::Succeeded, $finished->status);
        $this->assertSame(1, (int) $finished->error_count);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count());

        $record = $this->toolRuns($finished)[0];
        $this->assertSame(ToolRunStatus::Failed, $record->status);
        $this->assertSame('not_found', $record->error);

        $summary = (string) $finished->summary;
        $this->assertStringContainsString('Did not complete', $summary);
        $this->assertStringContainsString('create_task', $summary);
    }

    /* ------------------------------------------------------------------ *
     * Approval
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_call_needing_approval_parks_the_run_and_executes_nothing_further(): void
    {
        // Copilot executes reads freely and confirms every mutation.
        $run = $this->stage(AiMode::Copilot);

        $this->fakeProvider([
            $this->twoToolCalls(
                ['create_task', ['project_id' => (int) $this->project->id, 'title' => 'Needs a human first']],
                ['create_comment', ['subject_type' => 'project', 'subject_id' => (int) $this->project->id, 'body' => 'And this too.']],
            ),
            $this->assistantText('This should never be reached.'),
        ]);

        $finished = $this->runAgent($run, 'Create a task and comment on the project.');

        $this->assertSame(AiRunStatus::AwaitingApproval, $finished->status);
        $this->assertNull($finished->finished_at);

        $toolRuns = $this->toolRuns($finished);
        $this->assertCount(1, $toolRuns, 'The second call was attempted despite the run being parked.');

        $record = $toolRuns[0];
        $this->assertSame('create_task', $record->tool);
        $this->assertSame(ToolRunStatus::PendingApproval, $record->status);
        $this->assertTrue((bool) $record->approval_required);
        $this->assertNull($record->approved_by);
        $this->assertNull($record->subject_id);

        // Nothing was written, by either call.
        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
        $this->assertSame(0, Comment::withoutWorkspaceScope()->count());

        // And the loop stopped: no second provider round trip.
        $this->assertCount(1, Http::recorded());

        $this->assertStringContainsString('needs a human decision', (string) $finished->summary);

        Notification::assertSentTo($this->actor, AiApprovalRequired::class);
    }

    #[Test]
    public function an_approved_call_runs_when_the_run_resumes_and_a_rejected_one_never_does(): void
    {
        $run = $this->stage(AiMode::Copilot);

        $this->fakeProvider([
            $this->toolCall('create_task', [
                'project_id' => (int) $this->project->id,
                'title' => 'Needs a human first',
            ]),
        ]);

        $parked = $this->runAgent($run, 'Create a task.');

        $this->assertSame(AiRunStatus::AwaitingApproval, $parked->status);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count());

        // Somebody with ai.approve says yes. That decision, and nothing the model said, is
        // what unblocks the call.
        $record = $this->toolRuns($parked)[0];
        $record->forceFill([
            'status' => ToolRunStatus::Approved,
            'approved_by' => $this->actor->id,
            'approved_at' => now(),
        ])->save();

        $this->fakeProvider([$this->assistantText('Created it, as approved.')]);

        $runner = $this->app->make(AgentRunner::class);
        $finished = $runner->resume($runner->contextFor($parked))->fresh();

        $this->assertNotNull($finished);
        $this->assertSame(AiRunStatus::Succeeded, $finished->status);
        $this->assertSame('Needs a human first', Task::withoutWorkspaceScope()->firstOrFail()->title);
        $this->assertSame(ToolRunStatus::Succeeded, $record->fresh()?->status);
        $this->assertSame((int) $this->actor->id, (int) $record->fresh()?->approved_by);
    }

    #[Test]
    public function a_rejected_call_is_reported_and_the_run_never_executes_it(): void
    {
        $run = $this->stage(AiMode::Copilot);

        $this->fakeProvider([
            $this->toolCall('create_task', [
                'project_id' => (int) $this->project->id,
                'title' => 'Never wanted',
            ]),
        ]);

        $parked = $this->runAgent($run, 'Create a task.');

        $this->toolRuns($parked)[0]->forceFill([
            'status' => ToolRunStatus::Rejected,
            'rejected_reason' => 'We are not adding work this sprint.',
        ])->save();

        $this->fakeProvider([$this->assistantText('That was rejected, so I created nothing.')]);

        $runner = $this->app->make(AgentRunner::class);
        $finished = $runner->resume($runner->contextFor($parked))->fresh();

        $this->assertNotNull($finished);
        $this->assertSame(0, Task::withoutWorkspaceScope()->count());
        $this->assertSame(AiRunStatus::Succeeded, $finished->status);

        // The refusal reached the model as a tool result rather than being retried around.
        $body = $this->requestBodies()[0];
        $this->assertStringContainsString('rejected_by_human', $this->messagesOfRole($body, 'tool')[0]['content']);
    }

    /* ------------------------------------------------------------------ *
     * The jobs that carry the loop
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_queue_jobs_take_their_connection_queue_tries_and_backoff_from_config(): void
    {
        config([
            'ai.queue.connection' => 'database',
            'ai.queue.name' => 'ai',
            'ai.queue.tries' => 2,
            'ai.queue.backoff' => [10, 60],
        ]);

        foreach ([new RunAgentJob(1), new ResumeAgentRunJob(1)] as $job) {
            $this->assertSame('database', $job->connection);
            $this->assertSame('ai', $job->queue);
            $this->assertSame(2, $job->tries());
            $this->assertSame([10, 60], $job->backoff());
        }
    }

    #[Test]
    public function a_job_that_dies_closes_the_run_instead_of_leaving_it_running_for_ever(): void
    {
        $run = $this->stage();
        $run->markRunning();

        // A throwable's own message is never repeated: it is exactly the place a DSN or a
        // rejected credential turns up, and ai_runs.error is shown in the product.
        (new RunAgentJob((int) $run->getKey()))
            ->failed(new RuntimeException('connect failed for sk-live-abcdefghijklmnop1234'));

        $finished = $run->fresh();

        $this->assertNotNull($finished);
        $this->assertSame(AiRunStatus::Failed, $finished->status);
        $this->assertNotNull($finished->finished_at);
        $this->assertStringContainsString('RuntimeException', (string) $finished->error);
        $this->assertStringNotContainsString('sk-live', (string) $finished->error);
        $this->assertStringNotContainsString('sk-live', (string) $finished->summary);
    }

    #[Test]
    public function a_run_is_refused_without_touching_the_provider_when_the_kill_switch_is_engaged(): void
    {
        $run = $this->stage();

        AiSetting::query()
            ->where('workspace_id', $this->workspace->id)
            ->update(['kill_switch_engaged' => true]);

        // No Http::fake at all: preventStrayRequests() turns any provider call into a failure.
        $finished = $this->runAgent($run, 'Do something.');

        $this->assertSame(AiRunStatus::Cancelled, $finished->status);
        $this->assertSame(0, AiToolRun::withoutWorkspaceScope()->count());
        $this->assertSame(0, AiUsageDaily::query()->count());
    }

    /* ------------------------------------------------------------------ *
     * Staging
     * ------------------------------------------------------------------ */

    /**
     * A workspace with AI switched on, an owner to act for, a project with a real board, and
     * a queued run pointing at all of it.
     *
     * @param array<string, mixed> $overrides applied to the workspace's ai_settings row
     */
    private function stage(AiMode $mode = AiMode::Autonomous, array $overrides = []): AiRun
    {
        $this->workspace = $this->makeWorkspace(['timezone' => 'UTC']);
        $this->actor = $this->makeMember($this->workspace, WorkspaceRole::Owner, ['name' => 'Dana Owner']);

        $this->provider = AiProvider::factory()->active()->create([
            'name' => 'Test provider',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'temperature' => 0.2,
            'max_tokens' => 1024,
        ]);

        AiSetting::factory()->create([
            'workspace_id' => $this->workspace->id,
            'is_enabled' => true,
            'ai_provider_id' => $this->provider->id,
            'default_mode' => $mode,
            'autonomous_enabled' => $mode === AiMode::Autonomous,
            ...$overrides,
        ]);

        $this->project = $this->boardedProject($this->workspace);

        return AiRun::query()->create([
            'workspace_id' => $this->workspace->id,
            'project_id' => $this->project->id,
            'user_id' => $this->actor->id,
            'trigger' => 'chat',
            'mode' => $mode,
            'status' => AiRunStatus::Queued,
        ]);
    }

    /**
     * Drive the loop through the same context-rebuilding path the queue jobs use.
     */
    private function runAgent(AiRun $run, string $objective): AiRun
    {
        $runner = $this->app->make(AgentRunner::class);

        return $runner->run($runner->contextFor($run), $objective)->fresh() ?? $run;
    }

    private function boardedProject(Workspace $workspace): Project
    {
        $project = $this->makeProject($workspace, [], ['key' => 'WEB', 'name' => 'Marketing Campaign']);

        TaskStatus::factory()->for($project)->inCategory(StatusCategory::Todo)->asDefault()->create(['name' => 'To Do']);
        TaskStatus::factory()->for($project)->inCategory(StatusCategory::InProgress)->create(['name' => 'In Progress']);
        TaskStatus::factory()->for($project)->inCategory(StatusCategory::Done)->create(['name' => 'Done']);

        return $project->fresh();
    }

    /* ------------------------------------------------------------------ *
     * The faked provider
     * ------------------------------------------------------------------ */

    /**
     * @param list<array<string, mixed>> $bodies in the order the endpoint should return them
     */
    private function fakeProvider(array $bodies): void
    {
        $sequence = Http::sequence();

        foreach ($bodies as $body) {
            $sequence->push($body);
        }

        // A run that asks for more turns than the test scripted is a bug in the loop, not in
        // the fake, so the overflow answer is a plain "stop" rather than another tool call.
        $sequence->whenEmpty(Http::response($this->assistantText('Nothing further.')));

        Http::fake(['api.openai.com/*' => $sequence]);
    }

    /**
     * @param array<string, mixed> $body returned for every call, however many are made
     */
    private function fakeAlways(array $body): void
    {
        Http::fake(['api.openai.com/*' => static fn (Request $request): mixed => Http::response($body)]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments, string $id = 'call_1'): array
    {
        return $this->completion([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [$this->functionCall($id, $name, $arguments)],
        ], 'tool_calls');
    }

    /**
     * @param array{0: string, 1: array<string, mixed>} $first
     * @param array{0: string, 1: array<string, mixed>} $second
     * @return array<string, mixed>
     */
    private function twoToolCalls(array $first, array $second): array
    {
        return $this->completion([
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [
                $this->functionCall('call_1', $first[0], $first[1]),
                $this->functionCall('call_2', $second[0], $second[1]),
            ],
        ], 'tool_calls');
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantText(string $content): array
    {
        return $this->completion(['role' => 'assistant', 'content' => $content], 'stop');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function functionCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function completion(array $message, string $finishReason): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'gpt-4o-mini',
            'choices' => [[
                'index' => 0,
                'message' => $message,
                'finish_reason' => $finishReason,
            ]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 40],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Reading what was recorded
     * ------------------------------------------------------------------ */

    /**
     * @return list<AiToolRun>
     */
    private function toolRuns(AiRun $run): array
    {
        return AiToolRun::withoutWorkspaceScope()
            ->where('ai_run_id', $run->getKey())
            ->orderBy('sequence')
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * The decoded body of every request the loop sent, in order.
     *
     * @return list<array<string, mixed>>
     */
    private function requestBodies(): array
    {
        return array_map(
            static fn (array $exchange): array => (array) $exchange[0]->data(),
            Http::recorded()->all(),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return list<array<string, mixed>>
     */
    private function messagesOfRole(array $body, string $role): array
    {
        $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];

        return array_values(array_filter(
            $messages,
            static fn (mixed $message): bool => is_array($message) && ($message['role'] ?? null) === $role,
        ));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function countMessagesOfRole(array $body, string $role): int
    {
        return count($this->messagesOfRole($body, $role));
    }
}
