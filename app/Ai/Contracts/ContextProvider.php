<?php

declare(strict_types=1);

namespace App\Ai\Contracts;

use App\Ai\Agent\AgentContext;
use App\Ai\Support\ContextFragment;
use App\Ai\Support\PromptBuilder;

/**
 * Contributes one bounded slice of workspace state to a prompt.
 *
 * Two rules bind every implementation:
 *
 * 1. Read only what the acting user is authorised to read. Context is assembled from
 *    authorised queries, so a fragment can never show the model a record its user could not
 *    open themselves (AI_SECURITY, "AI to data the user cannot see").
 * 2. Return RAW text. Wrapping content in `<untrusted-data>` happens once, inside
 *    {@see PromptBuilder}. A provider that pre-wrapped its own output would
 *    have its wrapper escaped as data, which is the correct outcome but not a useful one.
 *
 * The `$tokenBudget` is a ceiling, not a target: return less whenever less is enough.
 */
interface ContextProvider
{
    public function contribute(AgentContext $ctx, int $tokenBudget): ContextFragment;
}
