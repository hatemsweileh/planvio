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
 * Carries one agent run onto the queue.
 *
 * Planvio has no persistent worker: on the hosting it targets, the queue is a database table
 * drained by a cron tick. So an AI request is never served inline — it is recorded as an
 * `ai_runs` row in `queued`, dispatched here, and the surface polls for the result
 * (docs/AI.md, "Limitations").
 *
 * ## Why {@see failed()} is not optional
 *
 * A run that dies without anybody closing it stays `running` for ever. The UI keeps saying
 * the assistant is working, the approvals queue never fires, and nothing in the system will
 * ever move it again — a worker that was killed mid-call leaves no second chance. So the
 * failure path marks the run failed with a sentence a person can read, and it is the last
 * thing that touches the row.
 *
 * The message it writes is Planvio's own. A throwable's message can carry a DSN, a URL with
 * credentials in it, or a file path, and `ai_runs.error` is displayed in the product
 * (CLAUDE.md rule 4). Only the exception's class name is recorded, which is enough to debug
 * from and cannot contain a secret.
 *
 * ## Why a retry is safe
 *
 * The run keeps its uuid across attempts, and every mutating call is keyed by
 * `sha1(run_uuid | tool | canonical_args)`. A second attempt therefore replays the tool calls
 * the first attempt completed instead of repeating them, so a worker that died halfway
 * through does not produce a second copy of everything it had already created
 * (ARCHITECTURE.md §7.5).
 */
final class RunAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param int $runId the `ai_runs` row to execute
     * @param string|null $objective overrides the stored objective; null uses the row's own
     */
    public function __construct(
        private readonly int $runId,
        private readonly ?string $objective = null,
    ) {
        $this->onConnection(self::connectionName());
        $this->onQueue(self::queueName());
    }

    public function tries(): int
    {
        $tries = config('ai.queue.tries');

        return is_int($tries) && $tries > 0 ? $tries : 2;
    }

    /**
     * Seconds before each retry; Laravel repeats the last value once it runs out.
     *
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
        return ['ai', 'ai-run:'.$this->runId];
    }

    public function handle(AgentRunner $runner): void
    {
        // System code with no tenant bound: the runner binds the run's own workspace before
        // it touches anything, and escaping the scope here says so rather than relying on it
        // being inert (ARCHITECTURE.md §3).
        $run = AiRun::withoutWorkspaceScope()->find($this->runId);

        if (! $run instanceof AiRun || $run->isTerminal()) {
            return;
        }

        // A run parked on a human decision belongs to ResumeAgentRunJob. Restarting the loop
        // here would re-propose the call somebody is already looking at.
        if ($run->status === AiRunStatus::AwaitingApproval) {
            return;
        }

        try {
            $context = $runner->contextFor($run);
        } catch (DomainException $e) {
            // The run can never be built — a deleted workspace, a removed account, AI turned
            // off. Retrying would fail identically, so it is closed here rather than thrown.
            $runner->abandon($run, $e->userMessage(), $e->userMessage());

            return;
        }

        $runner->run($context, $this->objective ?? (string) $run->objective);
    }

    /**
     * The queue has exhausted the retries, or the job was failed outright.
     */
    public function failed(?Throwable $exception): void
    {
        $run = AiRun::withoutWorkspaceScope()->find($this->runId);

        if (! $run instanceof AiRun || $run->isTerminal()) {
            return;
        }

        Log::error('ai.run.job_failed', [
            'ai_run_id' => $this->runId,
            'exception' => $exception === null ? null : class_basename($exception),
        ]);

        app(AgentRunner::class)->abandon(
            $run,
            __('The assistant stopped unexpectedly and the run was closed. Anything already recorded on it did happen; nothing else was attempted.'),
            'job_failed'.($exception === null ? '' : ':'.class_basename($exception)),
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
