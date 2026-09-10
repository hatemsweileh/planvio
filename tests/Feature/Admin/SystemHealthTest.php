<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Support\HealthCheck;
use App\Filament\Support\HealthReport;
use App\Filament\Support\HealthStatus;
use App\Filament\Support\SystemFacts;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The health checks, and the promise docs/CPANEL.md makes about them: Planvio never claims cron
 * is healthy without evidence.
 *
 * The scheduler assertions are the point of this file. Every other check can be wrong and be
 * noticed; a false green on cron is the failure that hides for a week and surfaces as a missed
 * deadline, so both the "never ran" and the "stopped" cases are pinned down here.
 */
final class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    private const SCHEDULER_KEY = 'system.scheduler.last_run_at';

    /*
     * There is deliberately no `Http::fake()` in setUp. Laravel keeps the first stub that
     * matches a URL, so a wildcard registered here could not be overridden by a test that needs
     * the probe to answer differently — and two of the tests below turn on exactly that.
     */

    /* ------------------------------------------------------------------ *
     * The scheduler
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_reports_the_scheduler_as_failed_when_no_heartbeat_has_ever_been_written(): void
    {
        $this->fakeRefusedEnvProbe();

        $check = $this->check('scheduler');

        $this->assertSame(
            HealthStatus::Failed,
            $check->status,
            'A missing heartbeat means cron has never run. Reporting anything softer would be the false green docs/CPANEL.md promises never to show.',
        );

        $this->assertNotEmpty($check->evidence);
        $this->assertNotNull($check->remedy);
    }

    #[Test]
    public function it_reports_the_scheduler_as_healthy_only_on_a_fresh_heartbeat(): void
    {
        $this->fakeRefusedEnvProbe();

        app(Settings::class)->set(self::SCHEDULER_KEY, Carbon::now()->toIso8601String());

        $check = $this->check('scheduler');

        $this->assertSame(HealthStatus::Healthy, $check->status);
        $this->assertNotEmpty($check->evidence, 'A healthy verdict must still state what it observed.');
    }

    #[Test]
    public function it_reports_the_scheduler_as_failed_when_the_heartbeat_has_gone_stale(): void
    {
        $this->fakeRefusedEnvProbe();

        app(Settings::class)->set(self::SCHEDULER_KEY, Carbon::now()->subHours(3)->toIso8601String());

        $check = $this->check('scheduler');

        $this->assertSame(HealthStatus::Failed, $check->status);
    }

    /* ------------------------------------------------------------------ *
     * The .env probe
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_reports_a_failure_when_the_env_file_is_served(): void
    {
        Http::fake(['*' => Http::response("APP_KEY=base64:secret\n", 200)]);

        $check = $this->check('env_file');

        $this->assertSame(HealthStatus::Failed, $check->status);
        $this->assertStringContainsString('200', implode(' ', $check->evidence));
    }

    #[Test]
    public function it_reports_healthy_when_the_env_file_is_refused(): void
    {
        $this->fakeRefusedEnvProbe();

        $this->assertSame(HealthStatus::Healthy, $this->check('env_file')->status);
    }

    #[Test]
    public function it_warns_rather_than_passing_when_the_env_probe_cannot_be_made(): void
    {
        // No fake at all: TestCase::preventStrayRequests() turns the probe into an exception,
        // which stands in for a host that cannot reach itself.

        $check = $this->check('env_file');

        $this->assertSame(
            HealthStatus::Warning,
            $check->status,
            'A check that could not run must never report healthy.',
        );
    }

    /* ------------------------------------------------------------------ *
     * The rest
     * ------------------------------------------------------------------ */

    #[Test]
    public function every_check_states_what_it_observed(): void
    {
        $this->fakeRefusedEnvProbe();

        app(Settings::class)->set(self::SCHEDULER_KEY, Carbon::now()->toIso8601String());

        foreach ((new HealthReport)->all() as $check) {
            $this->assertNotSame('', $check->label);
            $this->assertNotSame('', $check->summary);

            if ($check->status === HealthStatus::Healthy) {
                continue;
            }

            $this->assertNotNull(
                $check->remedy,
                "The {$check->key} check reported {$check->status->value} without saying what to do about it.",
            );
        }
    }

    #[Test]
    public function the_overall_status_is_the_worst_of_its_parts(): void
    {
        $this->fakeRefusedEnvProbe();

        $report = new HealthReport;
        $checks = $report->all();

        $this->assertSame(
            HealthStatus::Failed,
            $report->overall($checks),
            'With no scheduler heartbeat the installation cannot be reported as healthy overall.',
        );
    }

    #[Test]
    public function the_cache_check_makes_a_real_round_trip(): void
    {
        $this->fakeRefusedEnvProbe();

        $this->assertSame(HealthStatus::Healthy, $this->check('cache')->status);
    }

    #[Test]
    public function the_application_key_check_passes_on_a_valid_key(): void
    {
        $this->fakeRefusedEnvProbe();

        $this->assertSame(HealthStatus::Healthy, $this->check('app_key')->status);
    }

    #[Test]
    public function the_mail_check_warns_when_mail_is_discarded(): void
    {
        $this->fakeRefusedEnvProbe();

        config(['mail.default' => 'array']);

        $this->assertSame(HealthStatus::Warning, $this->check('mail')->status);
    }

    /* ------------------------------------------------------------------ *
     * System information
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_renders_cron_lines_with_the_real_php_binary_and_artisan_path(): void
    {
        $lines = SystemFacts::cronLines();

        $this->assertArrayHasKey('scheduler', $lines);
        $this->assertArrayHasKey('queue', $lines);

        foreach ($lines as $line) {
            $this->assertStringContainsString(PHP_BINARY, $line['command']);
            $this->assertStringContainsString(base_path('artisan'), $line['command']);
        }

        $this->assertStringContainsString('schedule:run', $lines['scheduler']['command']);
        $this->assertStringContainsString('* * * * *', $lines['scheduler']['schedule']);
        $this->assertStringContainsString('queue:work', $lines['queue']['command']);
        $this->assertStringContainsString('--stop-when-empty', $lines['queue']['command']);
    }

    #[Test]
    public function the_system_information_page_shows_the_copyable_cron_command(): void
    {
        $this->fakeRefusedEnvProbe();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $this->actingAs($admin)
            ->get('/admin/system-information')
            ->assertOk()
            ->assertSee(base_path('artisan'), escape: false)
            ->assertSee('schedule:run', escape: false);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * The answer a correctly configured web server gives for `/.env`.
     */
    private function fakeRefusedEnvProbe(): void
    {
        Http::fake(['*' => Http::response('', 403)]);
    }

    private function check(string $key): HealthCheck
    {
        foreach ((new HealthReport)->all() as $check) {
            if ($check->key === $key) {
                return $check;
            }
        }

        $this->fail("No health check named [{$key}] was produced.");
    }
}
