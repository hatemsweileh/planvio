<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Ai\Support\ContextFragment as PromptFragment;

/**
 * Satisfies `App\Ai\Contracts\ContextProvider::contribute()` for a {@see ContextSource}.
 *
 * The two contracts describe the same data at different granularities. A source produces
 * several separately-sourced fragments so the budget can drop one conversation turn without
 * losing the thread; `PromptBuilder` wants one labelled group whose items it wraps
 * individually. Folding one into the other is mechanical, and doing it here once means no
 * source has to know about the prompt layer at all.
 *
 * Trusted fragments are dropped on the way through. `contribute()` returns retrieved
 * *workspace content*, every item of which PromptBuilder wraps as untrusted data; the handful
 * of fragments this layer computes itself — the clock — belong in the developer brief, not
 * inside a wrapper that tells the model to treat them as somebody's text. {@see
 * ContextBuilder::trustedText()} is where the agent loop collects those.
 */
trait ContributesContext
{
    public function contribute(AgentContext $ctx, int $tokenBudget): PromptFragment
    {
        if (! $this->supports($ctx)) {
            return PromptFragment::empty($this->label());
        }

        $fragments = ContextBuilder::fitFragments($this->provide($ctx), $tokenBudget);

        return PromptFragment::of($this->label(), ContextBuilder::toItems($fragments));
    }

    /**
     * The header PromptBuilder shows above this source's records. Planvio's own text, so it
     * sits outside every wrapper.
     */
    protected function label(): string
    {
        return ContextBuilder::labelFor($this->key());
    }
}
