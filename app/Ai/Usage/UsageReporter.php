<?php

declare(strict_types=1);

namespace App\Ai\Usage;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The read side of `ai_usage_daily`: the queries behind AI → Usage in the admin panel.
 *
 * ## This class does not price anything, and that is a decision
 *
 * There is no cost column here, no rate table, and no `estimatedCost()`. Providers do not
 * return a price with a completion, prices change without notice, they differ by contract, by
 * region, by cache-hit ratio and by whether a token was an input, an output or a reasoning
 * token — and a self-hosted installation may be pointed at a local endpoint where the marginal
 * cost is electricity.
 *
 * Any figure Planvio printed with a currency symbol in front of it would therefore be a guess
 * wearing the clothes of a fact, and it would be believed: somebody would put it in a budget,
 * reconcile it against an invoice, and find it wrong. A number that is honestly absent is
 * better than a number that is confidently incorrect. So this returns token counts, run counts
 * and error counts, and the administrator — who knows what they actually pay — applies their
 * own rates.
 *
 * ## Shape
 *
 * Every method takes an inclusive date range and an optional workspace, and returns plain
 * arrays of integers with the totals already summed in SQL. Aggregating in the database rather
 * than hydrating models matters on the hosting Planvio targets: a year of buckets for a busy
 * install is tens of thousands of rows, and none of them need to become objects to be added up.
 */
final class UsageReporter
{
    private const TABLE = 'ai_usage_daily';

    /** How many rows a "top N" breakdown returns unless the caller says otherwise. */
    private const DEFAULT_LIMIT = 25;

    /* ------------------------------------------------------------------ *
     * Totals
     * ------------------------------------------------------------------ */

    /**
     * One row of totals for the whole range.
     *
     * @return array{days: int, runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}
     */
    public function summary(Carbon|string $from, Carbon|string $to, ?int $workspaceId = null): array
    {
        $row = $this->base($from, $to, $workspaceId)
            ->selectRaw('count(distinct '.$this->wrap('date').') as days')
            ->selectRaw($this->totalsSelect())
            ->first();

        return [
            'days' => self::int($row?->days),
            'runs' => self::int($row?->runs),
            'tool_calls' => self::int($row?->tool_calls),
            'tokens_in' => self::int($row?->tokens_in),
            'tokens_out' => self::int($row?->tokens_out),
            'tokens_total' => self::int($row?->tokens_in) + self::int($row?->tokens_out),
            'errors' => self::int($row?->errors),
        ];
    }

    /* ------------------------------------------------------------------ *
     * Breakdowns
     * ------------------------------------------------------------------ */

    /**
     * One row per calendar day, oldest first — the series behind the usage chart.
     *
     * Days with no activity are absent rather than zero-filled: the caller knows the range it
     * asked for and can decide whether a gap should be drawn as a zero or as a break.
     *
     * @return list<array{date: string, runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}>
     */
    public function byDay(Carbon|string $from, Carbon|string $to, ?int $workspaceId = null): array
    {
        $rows = $this->base($from, $to, $workspaceId)
            ->select('date')
            ->selectRaw($this->totalsSelect())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'date' => self::dateString($row->date),
            ] + $this->totals($row))
            ->all();
    }

    /**
     * Usage per workspace, heaviest first. `workspace_id` is null for platform-wide buckets —
     * usage that belonged to no tenant, or whose tenant has since been deleted.
     *
     * @return list<array{workspace_id: int|null, label: string, runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}>
     */
    public function byWorkspace(Carbon|string $from, Carbon|string $to, int $limit = self::DEFAULT_LIMIT): array
    {
        $rows = $this->base($from, $to)
            ->leftJoin('workspaces', 'workspaces.id', '=', self::TABLE.'.workspace_id')
            ->select(self::TABLE.'.workspace_id', 'workspaces.name')
            ->selectRaw($this->totalsSelect())
            ->groupBy(self::TABLE.'.workspace_id', 'workspaces.name')
            ->orderByDesc('tokens_in')
            ->orderByDesc('runs')
            ->limit(self::limit($limit))
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'workspace_id' => self::nullableInt($row->workspace_id),
                'label' => is_string($row->name) && $row->name !== ''
                    ? $row->name
                    : __('ai.usage.platform_bucket'),
            ] + $this->totals($row))
            ->all();
    }

    /**
     * Usage per acting user. `user_id` is null once the account behind a run has been deleted —
     * the audit trail outlives the account, so the usage does too.
     *
     * @return list<array{user_id: int|null, label: string, runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}>
     */
    public function byUser(
        Carbon|string $from,
        Carbon|string $to,
        ?int $workspaceId = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $rows = $this->base($from, $to, $workspaceId)
            ->leftJoin('users', 'users.id', '=', self::TABLE.'.user_id')
            ->select(self::TABLE.'.user_id', 'users.name')
            ->selectRaw($this->totalsSelect())
            ->groupBy(self::TABLE.'.user_id', 'users.name')
            ->orderByDesc('tokens_in')
            ->orderByDesc('runs')
            ->limit(self::limit($limit))
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'user_id' => self::nullableInt($row->user_id),
                'label' => is_string($row->name) && $row->name !== ''
                    ? $row->name
                    : __('ai.usage.unattributed'),
            ] + $this->totals($row))
            ->all();
    }

    /**
     * Usage per model, heaviest first. This is the breakdown an administrator needs to apply
     * their own per-model rates to, which is why the model name is carried on the bucket at all.
     *
     * @return list<array{model: string|null, label: string, runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}>
     */
    public function byModel(
        Carbon|string $from,
        Carbon|string $to,
        ?int $workspaceId = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $rows = $this->base($from, $to, $workspaceId)
            ->select('model')
            ->selectRaw($this->totalsSelect())
            ->groupBy('model')
            ->orderByDesc('tokens_in')
            ->orderByDesc('runs')
            ->limit(self::limit($limit))
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'model' => is_string($row->model) && $row->model !== '' ? $row->model : null,
                'label' => is_string($row->model) && $row->model !== ''
                    ? $row->model
                    : __('ai.usage.unknown_model'),
            ] + $this->totals($row))
            ->all();
    }

    /**
     * Usage per configured provider, heaviest first.
     *
     * @return list<array{ai_provider_id: int|null, label: string, runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}>
     */
    public function byProvider(
        Carbon|string $from,
        Carbon|string $to,
        ?int $workspaceId = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $rows = $this->base($from, $to, $workspaceId)
            ->leftJoin('ai_providers', 'ai_providers.id', '=', self::TABLE.'.ai_provider_id')
            ->select(self::TABLE.'.ai_provider_id', 'ai_providers.name')
            ->selectRaw($this->totalsSelect())
            ->groupBy(self::TABLE.'.ai_provider_id', 'ai_providers.name')
            ->orderByDesc('tokens_in')
            ->orderByDesc('runs')
            ->limit(self::limit($limit))
            ->get();

        return $rows
            ->map(fn (object $row): array => [
                'ai_provider_id' => self::nullableInt($row->ai_provider_id),
                'label' => is_string($row->name) && $row->name !== ''
                    ? $row->name
                    : __('ai.usage.unknown_provider'),
            ] + $this->totals($row))
            ->all();
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function base(Carbon|string $from, Carbon|string $to, ?int $workspaceId = null): Builder
    {
        $query = DB::table(self::TABLE)
            ->whereBetween(self::TABLE.'.date', [self::dateString($from), self::dateString($to)]);

        if ($workspaceId !== null) {
            $query->where(self::TABLE.'.workspace_id', $workspaceId);
        }

        return $query;
    }

    /**
     * The five aggregates every breakdown reports. A fixed string with no interpolated input —
     * the column names are constants of this class, not anything a caller supplies.
     */
    private function totalsSelect(): string
    {
        $table = self::TABLE;

        return "coalesce(sum({$table}.runs), 0) as runs,"
            ." coalesce(sum({$table}.tool_calls), 0) as tool_calls,"
            ." coalesce(sum({$table}.tokens_in), 0) as tokens_in,"
            ." coalesce(sum({$table}.tokens_out), 0) as tokens_out,"
            ." coalesce(sum({$table}.errors), 0) as errors";
    }

    /**
     * @return array{runs: int, tool_calls: int, tokens_in: int, tokens_out: int, tokens_total: int, errors: int}
     */
    private function totals(object $row): array
    {
        $in = self::int($row->tokens_in ?? null);
        $out = self::int($row->tokens_out ?? null);

        return [
            'runs' => self::int($row->runs ?? null),
            'tool_calls' => self::int($row->tool_calls ?? null),
            'tokens_in' => $in,
            'tokens_out' => $out,
            'tokens_total' => $in + $out,
            'errors' => self::int($row->errors ?? null),
        ];
    }

    private function wrap(string $column): string
    {
        return DB::connection()->getQueryGrammar()->wrap(self::TABLE.'.'.$column);
    }

    private static function dateString(mixed $value): string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return Carbon::parse(is_string($value) ? $value : 'now')->toDateString();
    }

    private static function limit(int $limit): int
    {
        return max(1, min($limit, 500));
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
