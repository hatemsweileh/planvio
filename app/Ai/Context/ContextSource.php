<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Ai\Contracts\ContextProvider;

/**
 * A retrieval source, in the shape {@see ContextBuilder} orchestrates.
 *
 * This extends the normative `App\Ai\Contracts\ContextProvider` rather than replacing it:
 * that interface is what `PromptBuilder` and the agent loop know about, and one provider must
 * not exist in two incompatible forms. The extra methods here are what the builder needs and
 * a single-fragment contract cannot express — whether a source applies to this run at all,
 * and a *list* of individually-sourced fragments so the budget can drop the oldest turn of a
 * conversation without discarding the rest of it.
 *
 * `contribute()` is satisfied for every implementation by {@see ContributesContext}, which
 * folds `provide()` down into the single labelled fragment the contract asks for.
 *
 * Two rules bind every implementation, and neither is optional:
 *
 *  1. **Workspace-scoped.** Every query names the bound workspace. `CurrentWorkspace` is
 *     bound around the whole build, but the scope is convenience, not authority
 *     (ARCHITECTURE.md §3) — say the workspace out loud.
 *
 *  2. **Permission-filtered.** A source returns only what the *acting user* may already read,
 *     decided through `AgentContext::can()`. A guest must never receive a project they cannot
 *     open, and the AI must never become a way to read something a person's own click would
 *     be refused (AI_SECURITY.md, "The pipeline").
 *
 * Fragments carrying workspace-derived text are always `trusted: false`, so PromptBuilder
 * wraps them.
 */
interface ContextSource extends ContextProvider
{
    /**
     * Stable identifier for this source — also the `kind` prefix of the fragment sources it
     * emits, which is what {@see ContextBuilder} orders and truncates against.
     */
    public function key(): string;

    /**
     * Whether this source has anything to contribute to $context. Called before
     * {@see self::provide()} so a source with no focus costs no queries.
     */
    public function supports(AgentContext $context): bool;

    /**
     * The fragments for this run, oldest first where the content has an age.
     *
     * @return list<ContextFragment>
     */
    public function provide(AgentContext $context): array;
}
