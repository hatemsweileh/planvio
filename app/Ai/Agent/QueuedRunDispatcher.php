<?php

declare(strict_types=1);

namespace App\Ai\Agent;

use App\Ai\Approvals\ResumesApprovedRuns;
use App\Ai\Automations\StartsAgentRuns;
use App\Jobs\Ai\ResumeAgentRunJob;
use App\Jobs\Ai\RunAgentJob;
use App\Models\AiRun;
use App\Models\AiToolRun;
use Illuminate\Support\Facades\DB;

/**
 * The one place that puts an agent run onto the queue.
 *
 * {@see ApprovalService} and {@see AutomationRunner} each declare what they need as a
 * narrow interface and accept null, so that a missing dispatcher leaves the decision
 * recorded and the run parked in `queued` rather than losing it. This class is the
 * implementation both of those interfaces are waiting for; without it bound, an approval is
 * a dead end and a due automation never starts.
 *
 * ## Why the dispatch is deferred to after commit
 *
 * Both callers dispatch from inside a transaction — the approval transition and the
 * automation claim are each a conditional UPDATE that must win before anything happens. On
 * the database queue the job row is written in that same transaction, so a worker can pick
 * it up before the commit lands and read a run that, from its connection, is still in its
 * previous state. `afterCommit()` is what makes the ordering deterministic.
 */
final class QueuedRunDispatcher implements ResumesApprovedRuns, StartsAgentRuns
{
    public function start(AiRun $run): void
    {
        $this->dispatchAfterCommit(
            RunAgentJob::dispatch($run->getKey(), $run->objective),
        );
    }

    public function resume(AiRun $run, AiToolRun $toolRun): void
    {
        /*
         * The tool run is not passed to the job. The runner reads the run's own pending
         * tool call back from the database when it resumes, so a job that is retried after
         * the call already executed sees the recorded status and advances rather than
         * running it a second time. Carrying the id in the payload would invite the
         * opposite: a stale retry re-executing an approved mutation.
         */
        $this->dispatchAfterCommit(
            ResumeAgentRunJob::dispatch($run->getKey()),
        );
    }

    /**
     * `afterCommit()` only defers when a transaction is actually open; outside one the
     * pending dispatch fires on destruct as usual, so this is safe on both paths.
     */
    private function dispatchAfterCommit(object $pending): void
    {
        if (DB::transactionLevel() > 0 && method_exists($pending, 'afterCommit')) {
            $pending->afterCommit();
        }
    }
}
