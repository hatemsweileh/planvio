<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Agent\QueuedRunDispatcher;
use App\Ai\Approvals\ResumesApprovedRuns;
use App\Ai\Automations\StartsAgentRuns;
use App\Jobs\Ai\ResumeAgentRunJob;
use App\Jobs\Ai\RunAgentJob;
use App\Models\AiRun;
use App\Models\AiToolRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ApprovalService and AutomationRunner both accept a null dispatcher and fail closed
 * without one: the decision is recorded and the run is parked in `queued`, so nothing is
 * lost — but nothing advances either. That is a safe default and an invisible failure,
 * which is the worst combination to leave unpinned.
 *
 * These tests exist because the layer shipped in exactly that state once: both interfaces
 * were declared, both callers accepted null, and neither was ever bound. An approval was a
 * dead end and a due automation never started, and every unit test still passed.
 */
final class RunDispatchWiringTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function both_dispatch_interfaces_are_bound(): void
    {
        $this->assertTrue(
            $this->app->bound(ResumesApprovedRuns::class),
            'An approved AI action would be recorded and then never execute.',
        );
        $this->assertTrue(
            $this->app->bound(StartsAgentRuns::class),
            'A due AI automation would be claimed and then never run.',
        );

        $this->assertInstanceOf(QueuedRunDispatcher::class, $this->app->make(ResumesApprovedRuns::class));
        $this->assertInstanceOf(QueuedRunDispatcher::class, $this->app->make(StartsAgentRuns::class));
    }

    #[Test]
    public function starting_a_run_puts_it_on_the_ai_queue(): void
    {
        Queue::fake();

        $run = $this->queuedRun();

        $this->app->make(StartsAgentRuns::class)->start($run);

        Queue::assertPushed(
            RunAgentJob::class,
            fn (RunAgentJob $job): bool => $job->queue === config('ai.queue.name'),
        );
    }

    #[Test]
    public function resuming_an_approved_call_puts_it_on_the_ai_queue(): void
    {
        Queue::fake();

        $run = $this->queuedRun();
        $toolRun = AiToolRun::factory()->create([
            'ai_run_id' => $run->getKey(),
            'workspace_id' => $run->workspace_id,
        ]);

        $this->app->make(ResumesApprovedRuns::class)->resume($run, $toolRun);

        Queue::assertPushed(
            ResumeAgentRunJob::class,
            fn (ResumeAgentRunJob $job): bool => $job->queue === config('ai.queue.name'),
        );
    }

    /**
     * The dispatcher is deliberately callable more than once: a queue worker retrying is
     * normal, and the run's own recorded status — not the dispatch — is what decides
     * whether the loop actually advances.
     */
    #[Test]
    public function dispatching_twice_is_safe(): void
    {
        Queue::fake();

        $run = $this->queuedRun();
        $dispatcher = $this->app->make(StartsAgentRuns::class);

        $dispatcher->start($run);
        $dispatcher->start($run);

        Queue::assertPushed(RunAgentJob::class, 2);
    }

    private function queuedRun(): AiRun
    {
        $workspace = $this->makeWorkspace();

        return AiRun::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $this->makeMember($workspace)->getKey(),
        ]);
    }
}
