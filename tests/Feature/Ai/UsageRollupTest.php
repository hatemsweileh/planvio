<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Usage\UsageRecorder;
use App\Ai\Usage\UsageReporter;
use App\Enums\AiRunStatus;
use App\Enums\WorkspaceRole;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rollup's one non-negotiable property: **no count is ever lost**.
 *
 * A read-modify-write rollup fails silently. Two workers finish two runs, both read the same
 * starting value, both write their own total, and one run's tokens simply never happened —
 * with nothing downstream able to notice, because the number that is short still looks like a
 * number. So the tests below do not check that the arithmetic is right in the easy case; they
 * stage the two interleavings that lose counts and require the result to be exact anyway.
 *
 * ## Staging a race without threads
 *
 * The suite runs on SQLite `:memory:`, where a second connection is a second database. Real
 * parallelism is therefore off the table — and would be weak evidence in any case, since a
 * passing thread race only says this particular interleaving was harmless.
 *
 * Both losing interleavings are staged deterministically instead:
 *
 *   1. *A competing write lands between two rollups.* If the recorder computed totals in PHP,
 *      the second rollup would overwrite the first writer's work. `beforeExecuting` is not
 *      needed here — the competing write simply happens between two calls.
 *   2. *Two writers both find the bucket missing and both insert.* `Connection::beforeExecuting`
 *      lets the test slip a competing insert in immediately before the recorder's own insert
 *      reaches the driver, so the recorder genuinely takes the unique-key violation and has to
 *      recover from it.
 */
final class UsageRollupTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'ai_usage_daily';

    /* ------------------------------------------------------------------ *
     * The plain case
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_run_creates_its_bucket_with_the_numbers_it_used(): void
    {
        [$workspace, $user, $provider] = $this->world();

        $this->recorder()->record($this->finishedRun($workspace, $user, $provider, [
            'tokens_in' => 1200,
            'tokens_out' => 340,
            'tool_call_count' => 4,
            'error_count' => 1,
        ]));

        $bucket = DB::table(self::TABLE)->first();

        $this->assertNotNull($bucket);
        $this->assertSame(1, (int) $bucket->runs);
        $this->assertSame(4, (int) $bucket->tool_calls);
        $this->assertSame(1200, (int) $bucket->tokens_in);
        $this->assertSame(340, (int) $bucket->tokens_out);
        $this->assertSame(1, (int) $bucket->errors);
        $this->assertSame((int) $workspace->getKey(), (int) $bucket->workspace_id);
        $this->assertSame((int) $user->getKey(), (int) $bucket->user_id);
        $this->assertSame((int) $provider->getKey(), (int) $bucket->ai_provider_id);
        $this->assertSame($provider->model, $bucket->model);
    }

    #[Test]
    public function runs_sharing_a_bucket_accumulate_into_one_row(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $recorder = $this->recorder();

        $recorder->record($this->finishedRun($workspace, $user, $provider, ['tokens_in' => 100, 'tokens_out' => 10]));
        $recorder->record($this->finishedRun($workspace, $user, $provider, ['tokens_in' => 250, 'tokens_out' => 25]));

        $this->assertSame(1, DB::table(self::TABLE)->count());

        $bucket = DB::table(self::TABLE)->first();
        $this->assertSame(2, (int) $bucket->runs);
        $this->assertSame(350, (int) $bucket->tokens_in);
        $this->assertSame(35, (int) $bucket->tokens_out);
    }

    #[Test]
    public function different_models_users_and_days_get_their_own_buckets(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $other = $this->makeMember($workspace, WorkspaceRole::Member);
        $recorder = $this->recorder();

        $recorder->record($this->finishedRun($workspace, $user, $provider));
        $recorder->record($this->finishedRun($workspace, $other, $provider));
        $recorder->record($this->finishedRun($workspace, $user, $provider, ['model' => 'a-different-model']));
        $recorder->record($this->finishedRun($workspace, $user, $provider, [
            'finished_at' => Carbon::now()->subDays(2),
        ]));

        $this->assertSame(4, DB::table(self::TABLE)->count());
    }

    /* ------------------------------------------------------------------ *
     * The interleavings that lose counts
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_competing_write_between_two_rollups_is_not_overwritten(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $recorder = $this->recorder();

        $recorder->record($this->finishedRun($workspace, $user, $provider, ['tokens_in' => 100, 'tokens_out' => 10]));

        // Another worker's rollup lands in the same bucket. A recorder that had read the row
        // and was about to write a total computed in PHP would erase all of this.
        DB::table(self::TABLE)->update([
            'runs' => DB::raw('runs + 40'),
            'tokens_in' => DB::raw('tokens_in + 9000'),
            'tokens_out' => DB::raw('tokens_out + 900'),
        ]);

        $recorder->record($this->finishedRun($workspace, $user, $provider, ['tokens_in' => 100, 'tokens_out' => 10]));

        $bucket = DB::table(self::TABLE)->first();

        $this->assertSame(42, (int) $bucket->runs);
        $this->assertSame(9200, (int) $bucket->tokens_in, 'A rollup overwrote a concurrent write instead of adding to it.');
        $this->assertSame(920, (int) $bucket->tokens_out);
    }

    #[Test]
    public function losing_the_race_to_create_the_bucket_still_lands_the_counts(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $run = $this->finishedRun($workspace, $user, $provider, [
            'tokens_in' => 500,
            'tokens_out' => 50,
            'tool_call_count' => 2,
        ]);

        $competitor = [
            'date' => Carbon::now()->toDateString(),
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'ai_provider_id' => $provider->getKey(),
            'model' => $provider->model,
            'runs' => 1,
            'tool_calls' => 3,
            'tokens_in' => 7,
            'tokens_out' => 3,
            'errors' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ];

        $this->interceptFirstBucketInsert($competitor);

        $this->recorder()->record($run);

        $this->assertSame(
            1,
            DB::table(self::TABLE)->count(),
            'The unique key should have refused the duplicate insert.',
        );

        $bucket = DB::table(self::TABLE)->first();

        // The competitor's row plus this run's numbers. Nothing may be dropped just because
        // the insert lost.
        $this->assertSame(2, (int) $bucket->runs);
        $this->assertSame(5, (int) $bucket->tool_calls);
        $this->assertSame(507, (int) $bucket->tokens_in);
        $this->assertSame(53, (int) $bucket->tokens_out);
    }

    #[Test]
    public function many_rollups_into_one_bucket_add_up_exactly(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $recorder = $this->recorder();

        for ($i = 0; $i < 50; $i++) {
            $recorder->record($this->finishedRun($workspace, $user, $provider, [
                'tokens_in' => 3,
                'tokens_out' => 2,
                'tool_call_count' => 1,
            ]));
        }

        $bucket = DB::table(self::TABLE)->first();

        $this->assertSame(1, DB::table(self::TABLE)->count());
        $this->assertSame(50, (int) $bucket->runs);
        $this->assertSame(50, (int) $bucket->tool_calls);
        $this->assertSame(150, (int) $bucket->tokens_in);
        $this->assertSame(100, (int) $bucket->tokens_out);
    }

    #[Test]
    public function a_platform_bucket_with_no_workspace_or_user_still_accumulates(): void
    {
        // The nullable half of the unique key. NULLs are distinct in a unique index on both
        // supported drivers, so this bucket cannot be deduplicated by the database — the
        // counts still have to be right, which is what the reporter's SUM() relies on.
        $run = AiRun::factory()->create([
            'user_id' => null,
            'ai_provider_id' => null,
            'model' => null,
            'status' => AiRunStatus::Succeeded,
            'finished_at' => Carbon::now(),
            'tokens_in' => 11,
            'tokens_out' => 7,
        ]);

        $recorder = $this->recorder();
        $recorder->record($run);
        $recorder->record($run);

        $this->assertSame(22, (int) DB::table(self::TABLE)->sum('tokens_in'));
        $this->assertSame(14, (int) DB::table(self::TABLE)->sum('tokens_out'));
        $this->assertSame(2, (int) DB::table(self::TABLE)->sum('runs'));
    }

    /* ------------------------------------------------------------------ *
     * Error accounting
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_run_that_failed_before_any_tool_call_still_counts_as_one_error(): void
    {
        [$workspace, $user, $provider] = $this->world();

        $this->recorder()->record($this->finishedRun($workspace, $user, $provider, [
            'status' => AiRunStatus::Failed,
            'error_count' => 0,
            'tokens_in' => 0,
            'tokens_out' => 0,
        ]));

        $this->assertSame(
            1,
            (int) DB::table(self::TABLE)->value('errors'),
            'A provider refusing every request must not show up as a healthy zero.',
        );
    }

    #[Test]
    public function a_successful_run_records_no_error(): void
    {
        [$workspace, $user, $provider] = $this->world();

        $this->recorder()->record($this->finishedRun($workspace, $user, $provider, [
            'status' => AiRunStatus::Succeeded,
            'error_count' => 0,
        ]));

        $this->assertSame(0, (int) DB::table(self::TABLE)->value('errors'));
    }

    /* ------------------------------------------------------------------ *
     * The reporter
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_reporter_totals_match_what_was_rolled_up(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $other = $this->makeMember($workspace, WorkspaceRole::Member);
        $recorder = $this->recorder();

        $recorder->record($this->finishedRun($workspace, $user, $provider, ['tokens_in' => 100, 'tokens_out' => 20, 'tool_call_count' => 2]));
        $recorder->record($this->finishedRun($workspace, $other, $provider, ['tokens_in' => 300, 'tokens_out' => 60, 'tool_call_count' => 5]));
        $recorder->record($this->finishedRun($workspace, $user, $provider, [
            'tokens_in' => 50,
            'tokens_out' => 5,
            'finished_at' => Carbon::now()->subDay(),
        ]));

        $reporter = $this->app->make(UsageReporter::class);
        $from = Carbon::now()->subDays(7);
        $to = Carbon::now();

        $summary = $reporter->summary($from, $to);

        $this->assertSame(3, $summary['runs']);
        $this->assertSame(7, $summary['tool_calls']);
        $this->assertSame(450, $summary['tokens_in']);
        $this->assertSame(85, $summary['tokens_out']);
        $this->assertSame(535, $summary['tokens_total']);
        $this->assertSame(2, $summary['days']);

        $byDay = $reporter->byDay($from, $to);
        $this->assertCount(2, $byDay);
        $this->assertSame(Carbon::now()->subDay()->toDateString(), $byDay[0]['date']);

        $byUser = $reporter->byUser($from, $to);
        $this->assertCount(2, $byUser);
        // Heaviest first: the second user's single 300-token run outweighs the first user's
        // two runs of 100 and 50.
        $this->assertSame(300, $byUser[0]['tokens_in']);
        $this->assertSame($other->name, $byUser[0]['label']);
        $this->assertSame(150, $byUser[1]['tokens_in']);
        $this->assertSame($user->name, $byUser[1]['label']);

        $byWorkspace = $reporter->byWorkspace($from, $to);
        $this->assertCount(1, $byWorkspace);
        $this->assertSame($workspace->name, $byWorkspace[0]['label']);
        $this->assertSame(450, $byWorkspace[0]['tokens_in']);

        $byModel = $reporter->byModel($from, $to);
        $this->assertCount(1, $byModel);
        $this->assertSame($provider->model, $byModel[0]['model']);
    }

    #[Test]
    public function the_reporter_never_returns_a_currency_figure(): void
    {
        [$workspace, $user, $provider] = $this->world();
        $this->recorder()->record($this->finishedRun($workspace, $user, $provider));

        $reporter = $this->app->make(UsageReporter::class);
        $from = Carbon::now()->subDay();
        $to = Carbon::now();

        // Providers do not return a price with a completion. A figure Planvio printed with a
        // currency symbol in front of it would be a guess that somebody put in a budget, so
        // there is deliberately no key here that could carry one.
        $forbidden = ['cost', 'price', 'amount', 'spend', 'currency', 'usd', 'dollars'];

        $rows = array_merge(
            [$reporter->summary($from, $to)],
            $reporter->byDay($from, $to),
            $reporter->byUser($from, $to),
            $reporter->byWorkspace($from, $to),
            $reporter->byModel($from, $to),
            $reporter->byProvider($from, $to),
        );

        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                foreach ($forbidden as $word) {
                    $this->assertStringNotContainsString(
                        $word,
                        strtolower((string) $key),
                        'The usage reporter must report tokens and let the administrator apply their own rates.',
                    );
                }
            }
        }
    }

    #[Test]
    public function the_reporter_can_be_narrowed_to_one_workspace(): void
    {
        [$workspace, $user, $provider] = $this->world();
        [$otherWorkspace, $otherUser, $otherProvider] = $this->world();
        $recorder = $this->recorder();

        $recorder->record($this->finishedRun($workspace, $user, $provider, ['tokens_in' => 10]));
        $recorder->record($this->finishedRun($otherWorkspace, $otherUser, $otherProvider, ['tokens_in' => 999]));

        $summary = $this->app->make(UsageReporter::class)->summary(
            Carbon::now()->subDay(),
            Carbon::now(),
            (int) $workspace->getKey(),
        );

        $this->assertSame(1, $summary['runs']);
        $this->assertSame(10, $summary['tokens_in']);
    }

    /* ------------------------------------------------------------------ *
     * World building
     * ------------------------------------------------------------------ */

    /**
     * @return array{0: Workspace, 1: User, 2: AiProvider}
     */
    private function world(): array
    {
        $workspace = $this->makeWorkspace();
        $user = $this->makeMember($workspace, WorkspaceRole::Owner);
        $provider = AiProvider::factory()->active()->create(['model' => 'gpt-4o-mini']);

        return [$workspace, $user, $provider];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function finishedRun(
        Workspace $workspace,
        User $user,
        AiProvider $provider,
        array $attributes = [],
    ): AiRun {
        return AiRun::factory()->create(array_merge([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'ai_provider_id' => $provider->getKey(),
            'model' => $provider->model,
            'status' => AiRunStatus::Succeeded,
            'finished_at' => Carbon::now(),
            'tokens_in' => 0,
            'tokens_out' => 0,
            'tool_call_count' => 0,
            'error_count' => 0,
        ], $attributes));
    }

    private function recorder(): UsageRecorder
    {
        return $this->app->make(UsageRecorder::class);
    }

    /**
     * Slip a competing row in immediately before the recorder's own insert reaches the driver,
     * so the recorder genuinely loses the race to create the bucket.
     *
     * @param array<string, mixed> $row
     */
    private function interceptFirstBucketInsert(array $row): void
    {
        $fired = false;

        DB::connection()->beforeExecuting(
            function (string $query, array $bindings, Connection $connection) use (&$fired, $row): void {
                if ($fired) {
                    return;
                }

                $normalised = strtolower($query);

                if (! str_starts_with(ltrim($normalised), 'insert') || ! str_contains($normalised, self::TABLE)) {
                    return;
                }

                // Set before the nested insert so this hook does not re-enter itself.
                $fired = true;

                $connection->table(self::TABLE)->insert($row);
            },
        );
    }
}
