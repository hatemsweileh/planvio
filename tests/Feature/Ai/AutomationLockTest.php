<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Ai\Automations\AutomationRunner;
use App\Ai\Automations\StartsAgentRuns;
use App\Enums\AiRunStatus;
use App\Enums\AiTrigger;
use App\Enums\WorkspaceRole;
use App\Models\AiAutomation;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The overlap guard, asserted as the thing it actually promises: two cron ticks that both
 * decide an automation is due result in exactly one execution.
 *
 * ## How the race is reproduced
 *
 * Not with threads. The suite runs against SQLite `:memory:`, where a second connection is a
 * second database, so genuine parallelism cannot be staged — and staging it would prove less
 * than what is done here anyway, because a passing thread race is only evidence that this run
 * happened to interleave harmlessly.
 *
 * What is reproduced instead is the *state* two racing ticks are in at the moment the race is
 * lost: each holds a model it loaded before either of them wrote anything, so each believes
 * the automation is free. That is precisely the check-then-act window, and an implementation
 * that consulted the model's own `lock_token` would pass both ticks through it. The tests
 * below hand the runner exactly that stale state and require it to refuse — which it can only
 * do by putting the freshness test in the UPDATE's WHERE clause and trusting the affected-row
 * count.
 */
final class AutomationLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml ships AI_AUTOMATIONS_ENABLED=false so nothing schedules itself by
        // accident. These tests are about the scheduler, so they turn it on deliberately.
        config()->set('ai.automations.enabled', true);
    }

    /* ------------------------------------------------------------------ *
     * The claim
     * ------------------------------------------------------------------ */

    #[Test]
    public function two_ticks_claiming_the_same_automation_produce_exactly_one_winner(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner);

        // Two cron ticks that each read the due list before either of them wrote anything.
        $firstTick = $this->reload($automation);
        $secondTick = $this->reload($automation);

        $runner = $this->runner();

        $this->assertTrue($runner->claim($firstTick), 'The first tick should take the lock.');

        $this->assertNull(
            $secondTick->lock_token,
            'The second tick is meant to still be holding the pre-claim state — that is the race.',
        );

        $this->assertFalse(
            $runner->claim($secondTick),
            'A second tick claimed an automation that was already locked. Overlapping cron ticks '
            .'would run the agent twice against the same objective.',
        );
    }

    #[Test]
    public function the_winning_claim_is_the_one_recorded_in_the_database(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner);

        $firstTick = $this->reload($automation);
        $secondTick = $this->reload($automation);

        $runner = $this->runner();
        $runner->claim($firstTick);
        $runner->claim($secondTick);

        $stored = $this->reload($automation);

        $this->assertSame($firstTick->lock_token, $stored->lock_token);
        $this->assertNotNull($stored->locked_until);
        $this->assertTrue($stored->locked_until->isFuture());
    }

    #[Test]
    public function an_inactive_automation_cannot_be_claimed(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner, ['is_active' => false]);

        $this->assertFalse($this->runner()->claim($automation));
    }

    #[Test]
    public function a_lock_whose_expiry_has_passed_can_be_reclaimed(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner, [
            'lock_token' => str_repeat('a', 64),
            'locked_until' => Carbon::now()->subMinute(),
        ]);

        // The expiry is what makes a worker killed mid-run recoverable: it cannot release
        // anything, so the lock has to time out on its own.
        $this->assertTrue($this->runner()->claim($this->reload($automation)));
    }

    /* ------------------------------------------------------------------ *
     * The tick
     * ------------------------------------------------------------------ */

    #[Test]
    public function two_ticks_over_the_same_due_automation_start_exactly_one_run(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $this->dueAutomation($workspace, $owner);

        $starter = $this->recordingStarter();
        $runner = $this->runner();

        $this->assertSame(1, $runner->runDue(), 'The first tick should start the run.');
        $this->assertSame(0, $runner->runDue(), 'The second tick should find nothing to do.');

        $this->assertSame(
            1,
            AiRun::withoutWorkspaceScope()->count(),
            'A second run row means the automation executed twice.',
        );
        $this->assertCount(1, $starter->started);
    }

    #[Test]
    public function a_run_still_in_flight_keeps_its_automation_out_of_the_due_list(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner);

        $runner = $this->runner();
        $this->assertSame(1, $runner->runDue());

        // The second, independent guard: pretend the lock timed out while the queued run is
        // still sitting there. Without this check an expired lock would stack a second run on
        // top of the first.
        AiAutomation::withoutWorkspaceScope()
            ->whereKey($automation->getKey())
            ->update(['lock_token' => null, 'locked_until' => null]);

        $this->assertTrue($runner->due()->isEmpty());
        $this->assertSame(0, $runner->runDue());
        $this->assertSame(1, AiRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function a_tick_starts_no_more_than_the_configured_budget(): void
    {
        config()->set('ai.automations.max_per_tick', 2);

        [$workspace, $owner] = $this->workspaceUsingAi();

        for ($i = 0; $i < 5; $i++) {
            $this->dueAutomation($workspace, $owner);
        }

        $this->assertSame(2, $this->runner()->runDue());
        $this->assertSame(2, AiRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function the_run_acts_as_the_person_who_created_the_automation(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $author = $this->makeMember($workspace, WorkspaceRole::Manager);
        $automation = $this->dueAutomation($workspace, $author);

        $this->assertSame(1, $this->runner()->runDue());

        $run = AiRun::withoutWorkspaceScope()->firstOrFail();

        $this->assertSame(
            (int) $author->getKey(),
            (int) $run->user_id,
            'An automation must borrow its author\'s authority and nobody else\'s.',
        );
        $this->assertNotSame((int) $owner->getKey(), (int) $run->user_id);
        $this->assertSame(AiTrigger::Automation, $run->trigger);
        $this->assertSame(AiRunStatus::Queued, $run->status);
        $this->assertSame((int) $automation->getKey(), (int) $run->ai_automation_id);
    }

    #[Test]
    public function an_automation_whose_author_cannot_use_ai_starts_nothing(): void
    {
        [$workspace] = $this->workspaceUsingAi();

        // A guest holds no `ai.use` in the capability matrix, so their standing instruction
        // must stop working — the automation can never do more than its author could.
        $guest = $this->makeMember($workspace, WorkspaceRole::Guest);
        $automation = $this->dueAutomation($workspace, $guest);

        $starter = $this->recordingStarter();

        $this->assertSame(0, $this->runner()->runDue());
        $this->assertSame([], $starter->started);

        $run = AiRun::withoutWorkspaceScope()->firstOrFail();
        $this->assertSame(AiRunStatus::Failed, $run->status);
        $this->assertNotNull($run->error, 'The refusal should be readable in the run log.');

        $automation = $this->reload($automation);
        $this->assertNull($automation->lock_token, 'A refused tick must still release its claim.');
        $this->assertSame(1, (int) $automation->failure_count);
    }

    #[Test]
    public function the_kill_switch_takes_every_automation_out_of_the_due_list(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $this->dueAutomation($workspace, $owner);

        AiSetting::query()
            ->where('workspace_id', $workspace->getKey())
            ->update(['kill_switch_engaged' => true]);

        $runner = $this->runner();

        $this->assertTrue($runner->due()->isEmpty());
        $this->assertSame(0, $runner->runDue());
        $this->assertSame(0, AiRun::withoutWorkspaceScope()->count());
    }

    #[Test]
    public function a_workspace_with_ai_switched_off_is_never_picked_up(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $this->dueAutomation($workspace, $owner);

        AiSetting::query()
            ->where('workspace_id', $workspace->getKey())
            ->update(['is_enabled' => false]);

        $this->assertSame(0, $this->runner()->runDue());
    }

    /* ------------------------------------------------------------------ *
     * The release
     * ------------------------------------------------------------------ */

    #[Test]
    public function releasing_clears_the_lock_and_puts_the_schedule_forward(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner, ['schedule_cron' => '*/30 * * * *']);

        $runner = $this->runner();
        $runner->claim($automation);
        $runner->release($automation, AiRunStatus::Succeeded);

        $stored = $this->reload($automation);

        $this->assertNull($stored->lock_token);
        $this->assertNull($stored->locked_until);
        $this->assertSame(1, (int) $stored->run_count);
        $this->assertSame(0, (int) $stored->failure_count);
        $this->assertSame(AiRunStatus::Succeeded->value, $stored->last_run_status);
        $this->assertNotNull($stored->last_run_at);
        $this->assertNotNull($stored->next_run_at);
        $this->assertTrue($stored->next_run_at->isFuture());
        $this->assertTrue($stored->next_run_at->lessThanOrEqualTo(Carbon::now()->addMinutes(31)));
    }

    #[Test]
    public function a_released_automation_becomes_claimable_again(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner);

        $runner = $this->runner();
        $runner->claim($automation);
        $runner->release($automation, AiRunStatus::Succeeded);

        // Reclaimable, but only once its schedule says so — the release moved next_run_at.
        $this->assertTrue($runner->claim($this->reload($automation)));
    }

    #[Test]
    public function a_failure_backs_the_automation_off_before_it_is_tried_again(): void
    {
        config()->set('ai.automations.failure_backoff_minutes', [15, 60]);

        [$workspace, $owner] = $this->workspaceUsingAi();
        // An every-minute schedule, so nothing but the backoff can be responsible for the gap.
        $automation = $this->dueAutomation($workspace, $owner, ['schedule_cron' => '* * * * *']);

        $runner = $this->runner();
        $runner->claim($automation);
        $runner->release($automation, AiRunStatus::Failed);

        $stored = $this->reload($automation);

        $this->assertSame(1, (int) $stored->failure_count);
        $this->assertNotNull($stored->next_run_at);
        $this->assertTrue(
            $stored->next_run_at->greaterThanOrEqualTo(Carbon::now()->addMinutes(14)),
            'A failing automation must not be retried on the very next tick.',
        );
    }

    #[Test]
    public function a_successful_run_clears_the_failure_streak(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner, ['failure_count' => 4]);

        $runner = $this->runner();
        $runner->claim($automation);
        $runner->release($automation, AiRunStatus::Succeeded);

        $this->assertSame(0, (int) $this->reload($automation)->failure_count);
    }

    #[Test]
    public function repeated_failures_switch_the_automation_off(): void
    {
        config()->set('ai.automations.disable_after_consecutive_failures', 3);
        config()->set('ai.automations.failure_backoff_minutes', [1]);

        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner, ['schedule_cron' => '* * * * *']);

        $runner = $this->runner();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $automation = $this->reload($automation);
            $automation->forceFill(['lock_token' => null, 'locked_until' => null])->save();

            $this->assertTrue($runner->claim($automation));
            $runner->release($automation, AiRunStatus::Failed);
        }

        $stored = $this->reload($automation);

        $this->assertFalse((bool) $stored->is_active);
        $this->assertNull(
            $stored->next_run_at,
            'A deactivated automation must leave the due list rather than sitting in it forever.',
        );
        $this->assertFalse($runner->claim($stored));
    }

    #[Test]
    public function an_unparseable_cron_expression_takes_the_automation_out_of_the_due_list(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi();
        $automation = $this->dueAutomation($workspace, $owner, ['schedule_cron' => 'every other tuesday']);

        $runner = $this->runner();
        $runner->claim($automation);
        $runner->release($automation, AiRunStatus::Succeeded);

        $stored = $this->reload($automation);

        // Not deactivated: the schedule is broken, and fixing the expression should be enough
        // without also having to remember to switch the automation back on.
        $this->assertTrue((bool) $stored->is_active);
        $this->assertNull($stored->next_run_at);
    }

    #[Test]
    public function the_cron_expression_is_read_in_the_workspace_timezone(): void
    {
        [$workspace, $owner] = $this->workspaceUsingAi(['timezone' => 'Australia/Sydney']);
        $automation = $this->dueAutomation($workspace, $owner, ['schedule_cron' => '0 9 * * *']);

        $runner = $this->runner();
        $runner->claim($automation);
        $runner->release($automation, AiRunStatus::Succeeded);

        $next = $this->reload($automation)->next_run_at;

        $this->assertNotNull($next);
        $this->assertSame(
            '09:00',
            $next->copy()->setTimezone('Australia/Sydney')->format('H:i'),
            '"Every day at 9" means nine o\'clock where the team is, not on the server.',
        );
    }

    /* ------------------------------------------------------------------ *
     * World building
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $attributes
     * @return array{0: Workspace, 1: User}
     */
    private function workspaceUsingAi(array $attributes = []): array
    {
        $workspace = $this->makeWorkspace($attributes);
        $owner = $this->makeMember($workspace, WorkspaceRole::Owner);

        AiSetting::factory()->enabled()->create(['workspace_id' => $workspace->getKey()]);

        return [$workspace, $owner];
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function dueAutomation(Workspace $workspace, User $author, array $attributes = []): AiAutomation
    {
        return AiAutomation::factory()->due()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $author->getKey(),
        ] + $attributes);
    }

    private function reload(AiAutomation $automation): AiAutomation
    {
        return AiAutomation::withoutWorkspaceScope()
            ->with(['workspace', 'creator'])
            ->findOrFail($automation->getKey());
    }

    private function runner(): AutomationRunner
    {
        return $this->app->make(AutomationRunner::class);
    }

    /**
     * Stands in for the agent-loop slice, which owns the real dispatcher.
     */
    private function recordingStarter(): object
    {
        $starter = new class implements StartsAgentRuns
        {
            /** @var list<int> */
            public array $started = [];

            public function start(AiRun $run): void
            {
                $this->started[] = (int) $run->getKey();
            }
        };

        $this->app->instance(StartsAgentRuns::class, $starter);

        return $starter;
    }
}
