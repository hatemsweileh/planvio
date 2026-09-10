<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Agent\AgentRunner;
use App\Enums\AiRunStatus;
use App\Exceptions\DomainException;
use App\Models\AiRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Restarts a run that stopped for a human decision.
 *
 * Dispatched when somebody approves or rejects a parked `ai_tool_runs` row. The run picks up
 * exactly where it left off: the calls a person approved are executed with the arguments that
 * person saw, a rejection becomes a tool result the model has to report rather than route
 * around, and the loop continues from there ({@see AgentRunner::resume()}).
 *
 * A run whose approval was rejected still comes through here. The alternative — silently
 * abandoning the run — would leave the person who asked for the work with no answer at all,
 * and the model with no chance to say what it did before it was stopped.
 *
 * Everything said about {@see RunAgentJob} applies here too: retries are safe because the
 * idempotency key is per run rather than per attempt, and {@see failed()} exists so a killed
 * worker cannot leave a run `running` for ever.
 */
final class ResumeAgentRunJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly int $runId)
    {
        $this->onConnection(self::connectionName());
        $this->onQueue(self::queueName());
    }

    public function tries(): int
    {
        $tries = config('ai.queue.tries');

        return is_int($tries) && $tries > 0 ? $tries : 2;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = config('ai.queue.backoff');

        if (! is_array($backoff) || $backoff === []) {
            return [10, 60];
        }

        return array_values(array_map(
            static fn (mixed $seconds): int => max(1, (int) $seconds),
            $backoff,
        ));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['ai', 'ai-resume:'.$this->runId];
    }

    public function handle(AgentRunner $runner): void
    {
        $run = AiRun::withoutWorkspaceScope()->find($this->runId);

        if (! $run instanceof AiRun || $run->isTerminal()) {
            return;
        }

        // Only a parked run is resumable. A queued one has never started and belongs to
        // RunAgentJob; a running one is already being carried by a worker.
        if ($run->status !== AiRunStatus::AwaitingApproval) {
            return;
        }

        try {
            $context = $runner->contextFor($run);
        } catch (DomainException $e) {
            $runner->abandon($run, $e->userMessage(), $e->userMessage());

            return;
        }

        $runner->resume($context);
    }

    public function failed(?Throwable $exception): void
    {
        $run = AiRun::withoutWorkspaceScope()->find($this->runId);

        if (! $run instanceof AiRun || $run->isTerminal()) {
            return;
        }

        Log::error('ai.run.resume_failed', [
            'ai_run_id' => $this->runId,
            'exception' => $exception === null ? null : class_basename($exception),
        ]);

        app(AgentRunner::class)->abandon(
            $run,
            __('The assistant could not continue after the approval and the run was closed. Approved actions that already ran did happen; nothing else was attempted.'),
            'resume_failed'.($exception === null ? '' : ':'.class_basename($exception)),
        );
    }

    private static function connectionName(): ?string
    {
        $connection = config('ai.queue.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    private static function queueName(): ?string
    {
        $queue = config('ai.queue.name');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
