<?php

declare(strict_types=1);

namespace App\Ai\Support;

/**
 * Looks for known injection phrasings in workspace content on its way into a prompt.
 *
 * This is instrumentation, not enforcement. Content that matches is wrapped and sent exactly
 * as content that does not: stripping it would corrupt legitimate records (a task titled
 * "Document what happens when someone tries to reveal your prompt" is a real task) and would
 * not stop a rephrased attack. What the flags buy is a reviewable signal on the run, which is
 * what `config('ai.injection_guard')` is for.
 *
 * A pattern that will not compile is skipped rather than allowed to abort a run. The guard is
 * optional; the run is not.
 */
final class InjectionScanner
{
    /**
     * Beyond this, scanning costs more than the signal is worth; the content is already
     * bounded by the context budget long before a prompt is assembled.
     */
    private const MAX_SCAN_CHARS = 100_000;

    private const MAX_MATCH_CHARS = 160;

    /**
     * @return list<InjectionFlag>
     */
    public function scan(string $source, string $content): array
    {
        if (! $this->enabled() || trim($content) === '') {
            return [];
        }

        $subject = mb_substr($content, 0, self::MAX_SCAN_CHARS);
        $keepMatch = $this->mayStoreMatch();
        $flags = [];

        foreach ($this->patterns() as $pattern) {
            $matches = [];
            $result = @preg_match('#'.str_replace('#', '\#', $pattern).'#i', $subject, $matches);

            if ($result !== 1) {
                continue;
            }

            $flags[] = new InjectionFlag(
                UntrustedData::source($source),
                $pattern,
                $keepMatch ? mb_substr((string) ($matches[0] ?? ''), 0, self::MAX_MATCH_CHARS) : null,
            );
        }

        return $flags;
    }

    public function enabled(): bool
    {
        return (bool) config('ai.injection_guard.enabled', true);
    }

    /**
     * @return list<string>
     */
    private function patterns(): array
    {
        $configured = config('ai.injection_guard.suspicious_patterns');

        if (! is_array($configured)) {
            return [];
        }

        $patterns = [];

        foreach ($configured as $pattern) {
            if (is_string($pattern) && $pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return $patterns;
    }

    /**
     * The matched text is workspace content, so it follows the same rule as prompt bodies.
     */
    private function mayStoreMatch(): bool
    {
        return (bool) config('ai.logging.store_prompts', false);
    }
}
