<?php

declare(strict_types=1);

namespace App\Ai\Approvals;

use App\Models\AiRun;
use App\Models\AiToolRun;

/**
 * How an approved tool call gets back onto the queue.
 *
 * {@see ApprovalService} owns the *decision* — who may approve, what is recorded, and that
 * approving twice executes once. It deliberately does not own the *execution*: the agent loop
 * is queued work (`docs/QUEUE.md`, "The `ai` queue"), and wiring the loop's job class into the
 * approval path would make the decision depend on the runner being loadable.
 *
 * So the service asks for this instead, and the runner's service provider binds it. When
 * nothing is bound the approval is still recorded in full and the run is left in `queued` for
 * a worker to collect — an approval is never lost because a dispatcher was missing.
 *
 * An implementation must be safe to call more than once for the same run: the service calls it
 * only for the single approval that won the transition, but a queue worker retrying is normal.
 */
interface ResumesApprovedRuns
{
    /**
     * Put $run back on the `ai` queue so it continues from $toolRun.
     */
    public function resume(AiRun $run, AiToolRun $toolRun): void;
}
