<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Support\TokenEstimator;
use App\Ai\Support\UntrustedData;

/**
 * One labelled slice of retrieved context, ready for `PromptBuilder` to place in the prompt.
 *
 * `trusted` is the field that matters. Anything derived from the workspace — a task title, a
 * comment, a wiki excerpt, a project name, a memory somebody wrote — is `false`, and
 * PromptBuilder wraps every false fragment in `<untrusted-data source="…">` before it
 * reaches the model (ARCHITECTURE.md §7.6). The `source` is what ends up in that attribute,
 * so it has to say where the text came from precisely enough to be useful in an audit:
 * `task:412`, not `task`.
 *
 * The only fragments marked `true` are ones this application computed itself out of values it
 * has already validated — the resolved clock, for instance. If you are unsure whether a
 * fragment qualifies, it does not: `false` costs a wrapper, `true` costs the guarantee.
 *
 * `tokens` is an estimate produced by {@see TokenEstimator}, the same estimator `PromptBuilder`
 * budgets with. Using a second, differently-tuned estimate here would mean the two halves of
 * the pipeline disagreed about how full the prompt was, which is worse than either estimate
 * being imprecise. The count includes the `<untrusted-data>` envelope, because that envelope
 * is sent.
 */
final readonly class ContextFragment
{
    public function __construct(
        public string $source,
        public string $content,
        public int $tokens,
        public bool $trusted = false,
    ) {}

    /**
     * Build a fragment and estimate its cost.
     *
     * Workspace-derived content must use the default `$trusted = false`.
     */
    public static function make(string $source, string $content, bool $trusted = false): self
    {
        $estimator = new TokenEstimator;

        $tokens = $estimator->estimate($content);

        if (! $trusted) {
            $tokens += $estimator->estimate(UntrustedData::wrap(UntrustedData::source($source), ''));
        }

        return new self(
            source: $source,
            content: $content,
            tokens: $tokens,
            trusted: $trusted,
        );
    }

    /**
     * Content this application produced from already-validated values, safe to place outside
     * the untrusted wrapper. Rare by design.
     */
    public static function trusted(string $source, string $content): self
    {
        return self::make($source, $content, true);
    }

    /**
     * The kind of thing this fragment describes — the part of the source before the first
     * colon. {@see ContextBuilder} keys its retention order and its labels off this.
     */
    public function kind(): string
    {
        $separator = mb_strpos($this->source, ':');

        return $separator === false ? $this->source : mb_substr($this->source, 0, $separator);
    }

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    /**
     * @return array{source: string, content: string, tokens: int, trusted: bool}
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'content' => $this->content,
            'tokens' => $this->tokens,
            'trusted' => $this->trusted,
        ];
    }

    public static function estimateTokens(string $content): int
    {
        return (new TokenEstimator)->estimate($content);
    }
}
