<?php

declare(strict_types=1);

namespace App\Ai\Automations;

use App\Ai\Approvals\ResumesApprovedRuns;
use App\Models\AiRun;

/**
 * How a claimed automation's run gets onto the `ai` queue.
 *
 * {@see AutomationRunner} owns the *decision* — which automations are due, which tick claims
 * them, what the acting authority is, and when the schedule advances. It deliberately does
 * not own the *execution*: the agent loop is queued work (`docs/QUEUE.md`, "The `ai` queue"),
 * and wiring the loop's job class into the scheduler path would make the tick depend on the
 * runner being loadable.
 *
 * So the tick asks for this instead, and the runner's service provider binds it. When nothing
 * is bound the `ai_runs` row is still written in full and left in `queued` for a worker to
 * collect — a due automation is never silently dropped because a dispatcher was missing.
 *
 * This is the automation-side twin of {@see ResumesApprovedRuns}, which does the same job for
 * a run continuing after a human approval. They are separate interfaces because they carry
 * different facts: one starts a run, the other resumes one at a named tool call.
 *
 * An implementation must be safe to call more than once for the same run. The tick calls it
 * only for the run it just created, but a queue worker retrying is normal, and the run's own
 * status is what decides whether the loop actually advances.
 */
interface StartsAgentRuns
{
    /**
     * Put the freshly created, still `queued` $run onto the `ai` queue.
     */
    public function start(AiRun $run): void;
}
