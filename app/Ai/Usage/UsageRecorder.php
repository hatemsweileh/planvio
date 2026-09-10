<?php

declare(strict_types=1);

namespace App\Ai\Usage;

use App\Enums\AiRunStatus;
use App\Models\AiRun;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Folds a finished {@see AiRun} into its `ai_usage_daily` bucket.
 *
 * One row per `(date, workspace, user, provider, model)`. The rollup exists because the
 * per-run detail is pruned on a retention window while the usage history is what an
 * administrator looks at months later, and because summing `ai_runs` across a year to draw a
 * chart is not something shared hosting should be asked to do on every page load.
 *
 * ## Why this is not `updateOrCreate()`
 *
 * Two queue workers can finish two runs in the same bucket at the same moment. Read the row,
 * add to it in PHP, write it back, and both workers read the same starting value and one
 * increment is lost — silently, and permanently, because nothing downstream can tell that the
 * number is short.
 *
 * So no count is ever computed in PHP. The write is `SET runs = runs + ?`, evaluated by the
 * database against whatever the row holds at that instant, which is correct under any
 * interleaving. Only when the bucket does not exist yet is a row inserted, and a lost insert
 * race is retried as an increment against the row the other writer just created.
 *
 * ## The one case the unique index cannot cover
 *
 * `ai_usage_daily`'s unique key spans nullable columns, and both MySQL and SQLite treat NULLs
 * as distinct there — so a bucket with, say, no provider recorded cannot be deduplicated by
 * the index. Two writers racing on such a bucket may therefore end up with two rows. That is
 * deliberately tolerated rather than papered over with a lock: every reader of this table
 * aggregates with `SUM()`, so two rows holding half the counts each report exactly the same
 * totals as one row holding all of them. Duplicate rows cost a little tidiness; a lock across
 * every rollup would cost throughput on the queue, and a read-modify-write would cost counts.
 */
final class UsageRecorder
{
    private const TABLE = 'ai_usage_daily';

    /**
     * Add one run's usage to its bucket.
     *
     * Safe to call for any run, including one that never reached the provider: a run that
     * failed before it spent a token still counts as a run and as an error, which is exactly
     * what makes the usage page useful for spotting a misconfigured provider.
     */
    public function record(AiRun $run): void
    {
        $bucket = $this->bucketFor($run);
        $counts = $this->countsFor($run);
        $now = Carbon::now();

        if ($this->add($bucket, $counts, $now) > 0) {
            return;
        }

        try {
            DB::table(self::TABLE)->insert($bucket + $counts + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        } catch (QueryException) {
            // Another writer created the bucket between the update and the insert. The unique
            // index refused the duplicate, which is the index doing its job; the counts still
            // have to land, so they are added to the row that won.
        }

        $this->add($bucket, $counts, $now);
    }

    /**
     * The relative increment. Returns the number of rows changed — zero means no bucket yet.
     *
     * `incrementEach` compiles to `column = column + <literal>`, so the addition happens inside
     * the database. Nothing here reads a count first, which is what makes concurrent rollups
     * safe rather than merely unlikely to collide.
     *
     * @param array<string, mixed> $bucket
     * @param array<string, int> $counts
     */
    private function add(array $bucket, array $counts, Carbon $now): int
    {
        return $this->locate($bucket)->incrementEach($counts, ['updated_at' => $now]);
    }

    /**
     * A query matching exactly one bucket.
     *
     * Null components are matched with `IS NULL` rather than `= NULL`, which matches nothing —
     * the difference between "the platform-wide bucket" and "a second platform-wide bucket
     * every single time".
     *
     * @param array<string, mixed> $bucket
     */
    private function locate(array $bucket): Builder
    {
        $query = DB::table(self::TABLE);

        foreach ($bucket as $column => $value) {
            $value === null
                ? $query->whereNull($column)
                : $query->where($column, $value);
        }

        return $query;
    }

    /**
     * The bucket key.
     *
     * The date comes from when the run finished, in the *application* timezone — one reference
     * for every row. Bucketing each workspace in its own zone would make the `date` column mean
     * something different from row to row, and the totals on the platform usage page would then
     * be a sum over overlapping days.
     *
     * @return array<string, mixed>
     */
    private function bucketFor(AiRun $run): array
    {
        $moment = $run->finished_at ?? $run->created_at ?? Carbon::now();

        return [
            'date' => $moment->toDateString(),
            'workspace_id' => self::key($run->workspace_id),
            'user_id' => self::key($run->user_id),
            'ai_provider_id' => self::key($run->ai_provider_id),
            'model' => is_string($run->model) && $run->model !== '' ? $run->model : null,
        ];
    }

    /**
     * What this run contributes.
     *
     * `errors` counts errors observed during the run, which is normally the run's own
     * `error_count` — the tool calls that failed. A run that failed outright without ever
     * reaching a tool has an `error_count` of zero and is still, unmistakably, one error, so it
     * is counted as one. Without that, the usage page shows a healthy zero for the failure mode
     * an administrator most needs to see: a provider that is refusing every request.
     *
     * @return array<string, int>
     */
    private function countsFor(AiRun $run): array
    {
        $errors = max(0, (int) $run->error_count);

        if ($errors === 0 && $run->status === AiRunStatus::Failed) {
            $errors = 1;
        }

        return [
            'runs' => 1,
            'tool_calls' => max(0, (int) $run->tool_call_count),
            'tokens_in' => max(0, (int) $run->tokens_in),
            'tokens_out' => max(0, (int) $run->tokens_out),
            'errors' => $errors,
        ];
    }

    private static function key(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }
}
