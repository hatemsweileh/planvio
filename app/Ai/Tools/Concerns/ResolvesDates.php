<?php

declare(strict_types=1);

namespace App\Ai\Tools\Concerns;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Services\DateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Container\Container;

/**
 * "Friday" into a date, in the workspace's timezone — or a question instead of a guess.
 *
 * {@see DateResolver} already refuses the genuinely ambiguous forms: "next Friday" means
 * different weeks to different English speakers, "03/04/2026" is two different days depending
 * on where the writer lives, and "this weekend" is not a day at all. It returns null for all
 * of them rather than picking one.
 *
 * What this trait adds is what a tool does with that null. A due date set from a coin flip is
 * a deadline somebody will miss and nobody will be able to explain, so the tool stops, changes
 * nothing, and hands back a result that tells the model exactly what to ask. That is the
 * behaviour the system prompt promises under "Working with dates", and the only way to keep
 * that promise is for the tool to be structurally unable to guess.
 *
 * Resolution is anchored to `workspaces.timezone` and `workspaces.week_starts_on`, never to
 * UTC and never to the server's zone: a workspace in Auckland asking on Monday morning must
 * not get last week.
 */
trait ResolvesDates
{
    /**
     * The date a phrase names, or null when it names nothing a machine may safely pick.
     */
    protected function resolveDate(string $input, AgentContext $ctx): ?CarbonImmutable
    {
        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        return $this->dateResolver()->resolve(
            $trimmed,
            $ctx->resolvedTimezone(),
            $ctx->now(),
            (int) ($ctx->workspace->week_starts_on ?? DateResolver::DEFAULT_WEEK_START),
        );
    }

    /**
     * One date argument, resolved.
     *
     * Three outcomes, deliberately distinct:
     *
     *   - `null` — the argument was absent, or was explicitly null to clear the column. The
     *     caller tells those apart with `Arguments::has()`.
     *   - a {@see ToolResult} — the phrase was ambiguous. Return it as-is: nothing has been
     *     written, and the model is being asked, not told.
     *   - a date — resolved in the workspace's own zone.
     */
    protected function dateArgument(
        Arguments $input,
        string $field,
        AgentContext $ctx,
    ): CarbonImmutable|ToolResult|null {
        $raw = $input->text($field);

        if ($raw === null) {
            return null;
        }

        $resolved = $this->resolveDate($raw, $ctx);

        return $resolved ?? $this->ambiguousDate($field, $raw, $ctx);
    }

    /**
     * The clarification request. It names the phrase, the timezone it would have been read
     * in, and the forms that are never ambiguous, so the model can come back with one answer
     * rather than three attempts.
     */
    protected function ambiguousDate(string $field, string $input, AgentContext $ctx): ToolResult
    {
        return ToolResult::failed(
            __('":value" could be more than one date, so :field was not set and nothing was changed. Ask which date is meant, then send it as YYYY-MM-DD. Times are read in the :timezone timezone; today there is :today.', [
                'value' => self::clipPhrase($input),
                'field' => $field,
                'timezone' => $ctx->resolvedTimezone(),
                'today' => $ctx->today()->toDateString(),
            ]),
            'ambiguous_date',
            [
                'field' => $field,
                'value' => self::clipPhrase($input),
                'timezone' => $ctx->resolvedTimezone(),
                'today' => $ctx->today()->toDateString(),
            ],
        );
    }

    /**
     * The phrase is workspace-derived text being echoed back into a summary the model reads,
     * so it is bounded before it goes anywhere.
     */
    private static function clipPhrase(string $value): string
    {
        return mb_strlen($value) <= 64 ? $value : mb_substr($value, 0, 63).'…';
    }

    /**
     * Resolved from the container rather than injected: a trait cannot promote a constructor
     * argument, and threading a stateless resolver through twenty tool constructors would buy
     * nothing. {@see DateResolver} holds no state and touches no database.
     */
    private function dateResolver(): DateResolver
    {
        return Container::getInstance()->make(DateResolver::class);
    }
}
