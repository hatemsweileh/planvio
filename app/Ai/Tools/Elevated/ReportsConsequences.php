<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Ai\Agent\AgentContext;
use App\Ai\Contracts\AiTool;

/**
 * A tool that can state its blast radius before it acts.
 *
 * High-risk and destructive calls are approved by a person, and a person can only judge
 * "delete this project" if they are told what else goes with it. AI_SECURITY.md, "Approval
 * gating", is explicit that an approval request records "the tool, the exact arguments, the
 * affected records and the consequences" — this interface is the last of those.
 *
 * The runner asks for the numbers *before* execution, so the answer describes the world as
 * it is now. It is a plain map of counted facts, ready to render:
 *
 * ```
 * ['project' => 'Website Redesign', 'tasks' => 47, 'milestones' => 6, 'activities' => 123]
 * ```
 *
 * Two rules keep it honest. The counts come from aggregates — `count()` on an indexed
 * column — never from loading records and measuring the collection, because the card has to
 * render for a project with forty thousand tasks. And a subject that cannot be resolved in
 * the bound workspace yields an empty array rather than an invented shape or an exception:
 * there is no blast radius to describe, and the call itself will fail the same way a moment
 * later.
 */
interface ReportsConsequences extends AiTool
{
    /**
     * Counted facts about what this call would affect, for the approval card.
     *
     * @param array<string, mixed> $args the arguments as received, unvalidated
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array;
}
