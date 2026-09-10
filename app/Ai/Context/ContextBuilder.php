<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Ai\Support\ContextFragment as PromptFragment;
use App\Ai\Support\ContextItem;

/**
 * Assembles the retrieved context for one run: runs the sources, applies the token budget,
 * and hands back an ordered list of fragments for `PromptBuilder` to place in the prompt.
 *
 * ## What this class is responsible for, and what it is not
 *
 * Each source decides *what* it may return — every one of them is workspace-scoped and
 * permission-filtered, and none of them may hand back something the acting user could not
 * already read. This class decides *how much* of it survives. Keeping those two jobs apart
 * matters: a budget is a resource decision and must never be able to act as an authorization
 * one, and an authorization decision must never depend on how full the prompt happened to be.
 *
 * ## The budget
 *
 * `RunLimits::maxContextTokens` (itself capped at `config('ai.limits.max_context_tokens')`)
 * is the ceiling. When the assembled fragments exceed it, they are dropped one at a time in
 * a fixed order: least durable kind first, and within a kind the **oldest first** — the
 * earliest conversation turn goes before the most recent one, and the running facts about
 * the workspace and the focused project are the last things to go. A run that lost its
 * workspace facts but kept a three-week-old chat turn would be worse than useless.
 *
 * If a single surviving fragment still overruns the budget on its own, its content is cut to
 * fit rather than dropped, with the cut marked. The method never returns a set that exceeds
 * the budget, and never returns nothing when there was something to say.
 *
 * Per-source caps — how many projects, tasks, comments, activities, memories — live in
 * `config('ai.context')` and are applied by the sources, before any of this.
 *
 * ## Handing over to PromptBuilder
 *
 * {@see self::toPromptFragments()} converts the result into the `App\Ai\Support\ContextFragment`
 * list `PromptBuilder` consumes, and {@see self::trustedText()} returns the handful of
 * fragments this layer computed itself, for the developer brief. Wrapping stays where it
 * belongs: nothing here emits an `<untrusted-data>` element, it only budgets for one.
 */
final class ContextBuilder
{
    /**
     * How long each kind of fragment survives when the budget is tight. Higher stays longer.
     *
     * The order is the answer to "what does the model most need in order to be useful and
     * least likely to invent something": the clock and the workspace facts anchor every
     * relative date and every name it will use; the focused project and task are what the
     * request is about; memory is standing guidance; activity and conversation history are
     * the most expendable, and the most naturally aged.
     */
    private const RETENTION = [
        'clock' => 100,
        'workspace' => 90,
        'project' => 80,
        'task' => 70,
        'memory' => 50,
        'activity' => 30,
        'conversation' => 10,
    ];

    private const DEFAULT_RETENTION = 40;

    /**
     * Headers shown above each group of records. Planvio's own text, addressed to the model,
     * so it sits outside every wrapper and is deliberately not translated.
     */
    private const LABELS = [
        'clock' => 'Current time',
        'workspace' => 'Workspace',
        'project' => 'Focused project',
        'task' => 'Focused task',
        'memory' => 'Remembered about this workspace',
        'activity' => 'Recent activity',
        'conversation' => 'Earlier in this conversation',
    ];

    /**
     * @param list<ContextSource> $sources empty resolves the built-in set, in order
     */
    public function __construct(private readonly array $sources = []) {}

    /**
     * @param list<ContextSource> $sources
     */
    public static function withProviders(array $sources): self
    {
        return new self($sources);
    }

    /**
     * The retrieved context for $context, in prompt order.
     *
     * @param int|null $tokenBudget narrows the run's budget; it can never widen it
     * @return list<ContextFragment>
     */
    public function build(AgentContext $context, ?int $tokenBudget = null): array
    {
        $limits = $tokenBudget === null
            ? $context->limits
            : $context->limits->withContextTokens($tokenBudget);

        /** @var list<ContextFragment> $fragments */
        $fragments = $context->bindWorkspace(function () use ($context): array {
            $collected = [];

            foreach ($this->providers() as $source) {
                if (! $source->supports($context)) {
                    continue;
                }

                foreach ($source->provide($context) as $fragment) {
                    if (! $fragment->isEmpty()) {
                        $collected[] = $fragment;
                    }
                }
            }

            return $collected;
        });

        return self::fitFragments($fragments, $limits->maxContextTokens);
    }

    /**
     * The sources in the order their fragments appear in the prompt.
     *
     * Built lazily so `app(ContextBuilder::class)` works with no container wiring, while a
     * test or a caller with a narrower need can still inject its own list.
     *
     * @return list<ContextSource>
     */
    public function providers(): array
    {
        if ($this->sources !== []) {
            return $this->sources;
        }

        return [
            new WorkspaceContextProvider,
            new ProjectContextProvider,
            new TaskContextProvider,
            new MemoryContextProvider,
            new ActivityContextProvider,
            new ConversationContextProvider,
        ];
    }

    /* ------------------------------------------------------------------ *
     * Handing over to PromptBuilder
     * ------------------------------------------------------------------ */

    /**
     * Group workspace-derived fragments into the labelled groups `PromptBuilder` wraps.
     *
     * Trusted fragments are excluded: they are not somebody's text and do not belong inside a
     * wrapper that says they are. {@see self::trustedText()} collects those instead.
     *
     * @param list<ContextFragment> $fragments
     * @return list<PromptFragment>
     */
    public static function toPromptFragments(array $fragments): array
    {
        /** @var array<string, list<ContextItem>> $grouped */
        $grouped = [];

        foreach ($fragments as $fragment) {
            if ($fragment->trusted || $fragment->isEmpty()) {
                continue;
            }

            $grouped[$fragment->kind()][] = new ContextItem($fragment->source, $fragment->content);
        }

        $prompt = [];

        foreach ($grouped as $kind => $items) {
            $prompt[] = PromptFragment::of(self::labelFor($kind), $items);
        }

        return $prompt;
    }

    /**
     * The fragments this layer computed itself, for the developer brief.
     *
     * @param list<ContextFragment> $fragments
     */
    public static function trustedText(array $fragments): string
    {
        $parts = [];

        foreach ($fragments as $fragment) {
            if ($fragment->trusted && ! $fragment->isEmpty()) {
                $parts[] = $fragment->content;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param list<ContextFragment> $fragments
     * @return list<ContextItem>
     */
    public static function toItems(array $fragments): array
    {
        $items = [];

        foreach ($fragments as $fragment) {
            if ($fragment->trusted || $fragment->isEmpty()) {
                continue;
            }

            $items[] = new ContextItem($fragment->source, $fragment->content);
        }

        return $items;
    }

    public static function labelFor(string $kind): string
    {
        return self::LABELS[$kind] ?? ucfirst(str_replace('_', ' ', $kind));
    }

    /* ------------------------------------------------------------------ *
     * Budget
     * ------------------------------------------------------------------ */

    /**
     * @param list<ContextFragment> $fragments
     */
    public static function totalTokens(array $fragments): int
    {
        $total = 0;

        foreach ($fragments as $fragment) {
            $total += $fragment->tokens;
        }

        return $total;
    }

    /**
     * Drop and, as a last resort, trim fragments until the set fits $budget.
     *
     * @param list<ContextFragment> $fragments
     * @return list<ContextFragment>
     */
    public static function fitFragments(array $fragments, int $budget): array
    {
        if ($budget <= 0) {
            return [];
        }

        if ($fragments === [] || self::totalTokens($fragments) <= $budget) {
            return $fragments;
        }

        // Emission order is preserved for the result, so the drop order is expressed as a
        // list of positions rather than by reordering the fragments themselves.
        $order = array_keys($fragments);

        usort($order, static function (int $a, int $b) use ($fragments): int {
            $rank = self::retention($fragments[$a]) <=> self::retention($fragments[$b]);

            // Same kind: the older one — the one emitted earlier — goes first.
            return $rank !== 0 ? $rank : $a <=> $b;
        });

        $kept = $fragments;

        foreach ($order as $position) {
            if (self::totalTokens(array_values($kept)) <= $budget) {
                break;
            }

            // Never drop the last fragment standing: something has to reach the model, so
            // the final one is cut down to size instead.
            if (count($kept) === 1) {
                break;
            }

            unset($kept[$position]);
        }

        $kept = array_values($kept);

        if (self::totalTokens($kept) <= $budget) {
            return $kept;
        }

        $fitted = self::fit($kept[0], $budget);

        return $fitted === null ? [] : [$fitted];
    }

    private static function retention(ContextFragment $fragment): int
    {
        return self::RETENTION[$fragment->kind()] ?? self::DEFAULT_RETENTION;
    }

    /**
     * Shrink one fragment until its estimate fits, or give up.
     *
     * The cut is marked with an ellipsis so the model can see the content is partial rather
     * than treating a truncated list as complete.
     */
    private static function fit(ContextFragment $fragment, int $budget): ?ContextFragment
    {
        if ($fragment->tokens <= $budget) {
            return $fragment;
        }

        $characters = mb_strlen($fragment->content);

        while ($characters > 0) {
            $characters = (int) floor($characters * 0.85);

            $candidate = ContextFragment::make(
                $fragment->source,
                mb_substr($fragment->content, 0, $characters).'…',
                $fragment->trusted,
            );

            if ($candidate->tokens <= $budget) {
                return $candidate;
            }
        }

        return null;
    }
}
