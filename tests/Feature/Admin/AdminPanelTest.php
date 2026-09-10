<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Ai\Usage\UsageRecorder;
use App\Enums\AiRunStatus;
use App\Filament\Widgets\AiRunsToday;
use App\Filament\Widgets\FailedJobs;
use App\Filament\Widgets\PlatformTotals;
use App\Filament\Widgets\RecentAuditEvents;
use App\Models\AiAutomation;
use App\Models\AiPolicy;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiToolRun;
use App\Models\AuditLog;
use App\Models\ProjectTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Every screen in the Filament panel, loaded as a platform administrator.
 *
 * The panel is the one surface in Planvio nobody exercises by accident: a broken column
 * definition or a lazy-loaded relation on a rarely-opened log page would sit there until the
 * day somebody needed it, which by definition is a bad day. So this asserts a 200 for each of
 * them against real records, with `Model::preventLazyLoading()` active — an N+1 in any table
 * fails the suite rather than the production page.
 *
 * @fixture-secrets The provider API keys below are invented placeholders. The assertions
 * that use them are `assertDontSee` — proving a stored key never reaches a rendered page
 * requires a key that would be visible if it did. Read by the release script's secret
 * sweep; only honoured under `tests/`.
 */
final class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);

        // The health page fetches this installation's own /.env. Faked so the suite never
        // reaches the network, and so the check under test has a definite answer.
        Http::fake(['*' => Http::response('', 403)]);
    }

    /* ------------------------------------------------------------------ *
     * Every page renders
     * ------------------------------------------------------------------ */

    /**
     * @return list<array{0: string}>
     */
    public static function panelUrls(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            '/admin',

            '/admin/users',
            '/admin/users/create',

            '/admin/workspaces',

            '/admin/ai-providers',
            '/admin/ai-providers/create',

            '/admin/ai-policies',
            '/admin/ai-policies/create',

            '/admin/ai-automations',
            '/admin/ai-automations/create',

            '/admin/project-templates',
            '/admin/project-templates/create',

            '/admin/settings',
            '/admin/settings/create',

            '/admin/ai-runs',
            '/admin/ai-tool-runs',
            '/admin/audit-logs',
            '/admin/webhook-deliveries',

            '/admin/system-information',
            '/admin/system-health',
            '/admin/maintenance-mode',
            '/admin/ai-usage',
        ]);
    }

    #[Test]
    #[DataProvider('panelUrls')]
    public function it_renders_every_panel_screen_for_a_platform_administrator(string $url): void
    {
        $this->seedPanelRecords();

        $this->actingAs($this->admin)
            ->get($url)
            ->assertOk();
    }

    #[Test]
    public function it_renders_every_record_screen(): void
    {
        $records = $this->seedPanelRecords();

        $urls = [
            '/admin/users/'.$this->admin->getKey().'/edit',
            '/admin/workspaces/'.$records['workspace']->getKey().'/edit',
            '/admin/ai-providers/'.$records['provider']->getKey().'/edit',
            '/admin/ai-policies/'.$records['policy']->getKey().'/edit',
            '/admin/ai-automations/'.$records['automation']->getKey().'/edit',
            '/admin/project-templates/'.$records['template']->getKey().'/edit',
            '/admin/settings/'.$records['setting']->getKey().'/edit',
            '/admin/ai-runs/'.$records['run']->getKey(),
            '/admin/audit-logs/'.$records['audit']->getKey(),
            '/admin/webhook-deliveries/'.$records['delivery']->getKey(),
        ];

        foreach ($urls as $url) {
            $this->actingAs($this->admin)
                ->get($url)
                ->assertOk();
        }
    }

    /* ------------------------------------------------------------------ *
     * Who may not
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_refuses_a_member_who_is_not_a_platform_administrator(): void
    {
        $member = User::factory()->create(['is_admin' => false, 'is_active' => true]);

        foreach (['/admin', '/admin/users', '/admin/ai-providers', '/admin/system-health'] as $url) {
            $response = $this->actingAs($member)->get($url);

            $this->assertContains(
                $response->getStatusCode(),
                [403, 404],
                "A non-administrator reached {$url}. This is an authorization breach.",
            );
        }
    }

    #[Test]
    public function it_refuses_a_deactivated_platform_administrator(): void
    {
        $suspended = User::factory()->create(['is_admin' => true, 'is_active' => false]);

        $response = $this->actingAs($suspended)->get('/admin/users');

        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    #[Test]
    public function it_refuses_a_guest(): void
    {
        $this->get('/admin/users')->assertRedirect();
    }

    /* ------------------------------------------------------------------ *
     * The screens carry their content, not just a 200
     * ------------------------------------------------------------------ */

    /**
     * The widgets are lazy — Filament renders each as a placeholder and loads it on a second
     * Livewire round trip — so the dashboard HTML does not contain their content and asserting
     * on it would prove nothing. Each is driven directly instead.
     */
    #[Test]
    public function the_dashboard_carries_its_widgets(): void
    {
        $records = $this->seedPanelRecords();

        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(PlatformTotals::class)
            ->assertOk()
            ->assertSee(__('Workspaces'))
            ->assertSee(__('People'));

        Livewire::test(AiRunsToday::class)
            ->assertOk()
            ->assertSee(__('AI runs today'));

        Livewire::test(RecentAuditEvents::class)
            ->assertOk()
            ->assertSee(__('Recent activity'))
            ->assertSee($records['audit']->event);

        Livewire::test(FailedJobs::class)
            ->assertOk()
            ->assertSee(__('Failed jobs'));
    }

    #[Test]
    public function the_failed_jobs_widget_lists_and_clears_what_the_queue_gave_up_on(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('admin');

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Ai\\RunAgentJob']),
            'exception' => "RuntimeException: the endpoint refused the request\n#0 somewhere",
            'failed_at' => Carbon::now(),
        ]);

        Livewire::test(FailedJobs::class)
            ->assertSee('RunAgentJob')
            ->assertSee('RuntimeException: the endpoint refused the request')
            ->call('deleteAll');

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin.failed_jobs_flushed']);
    }

    #[Test]
    public function the_run_view_shows_the_tool_trace(): void
    {
        $records = $this->seedPanelRecords();

        $tool = AiToolRun::withoutWorkspaceScope()
            ->where('ai_run_id', $records['run']->getKey())
            ->firstOrFail();

        $this->actingAs($this->admin)
            ->get('/admin/ai-runs/'.$records['run']->getKey())
            ->assertOk()
            ->assertSee(__('Tool trace'))
            ->assertSee($tool->tool);
    }

    #[Test]
    public function the_usage_page_reports_counts_and_refuses_to_price_them(): void
    {
        $records = $this->seedPanelRecords();

        // Rolled up through the recorder rather than written straight into the table: that is
        // the only path production uses, so it is the only one worth asserting against.
        $records['run']->forceFill([
            'status' => AiRunStatus::Succeeded,
            'finished_at' => Carbon::now(),
            'tokens_in' => 3456,
            'tokens_out' => 789,
        ])->save();

        app(UsageRecorder::class)->record($records['run']);

        $this->actingAs($this->admin)
            ->get('/admin/ai-usage')
            ->assertOk()
            ->assertSee('3,456')
            ->assertSee(__('Why there is no cost figure here'));
    }

    /* ------------------------------------------------------------------ *
     * Secrets
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_never_renders_a_provider_api_key(): void
    {
        $provider = AiProvider::factory()->create([
            'api_key' => 'sk-liveprovidersecret0123456789abcd',
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/admin/ai-providers/'.$provider->getKey().'/edit')
            ->assertOk();

        $response->assertDontSee('sk-liveprovidersecret0123456789abcd', escape: false);
        $response->assertSee($provider->maskedApiKey(), escape: false);
    }

    #[Test]
    public function it_never_renders_a_provider_api_key_in_the_list(): void
    {
        AiProvider::factory()->create([
            'name' => 'Listed provider',
            'api_key' => 'sk-listedprovidersecret9876543210zz',
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/ai-providers')
            ->assertOk()
            ->assertDontSee('sk-listedprovidersecret9876543210zz', escape: false);
    }

    /* ------------------------------------------------------------------ *
     * Fixtures
     * ------------------------------------------------------------------ */

    /**
     * One of everything the panel lists, so no table is asserted empty.
     *
     * @return array<string, mixed>
     */
    private function seedPanelRecords(): array
    {
        $workspace = Workspace::factory()->create();
        $member = $this->makeMember($workspace);

        $provider = AiProvider::factory()->default()->create();

        $policy = AiPolicy::factory()->create(['workspace_id' => $workspace->getKey()]);

        $automation = AiAutomation::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'created_by' => $member->getKey(),
        ]);

        $template = ProjectTemplate::factory()->create(['workspace_id' => null]);

        $setting = Setting::factory()->create(['key' => 'panel.test.key', 'value' => 'value']);

        $run = AiRun::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'user_id' => $member->getKey(),
            'ai_provider_id' => $provider->getKey(),
        ]);

        AiToolRun::factory()->create([
            'ai_run_id' => $run->getKey(),
            'workspace_id' => $workspace->getKey(),
            'user_id' => $member->getKey(),
        ]);

        $audit = AuditLog::factory()->create(['user_id' => $member->getKey()]);

        $webhook = Webhook::factory()->create(['workspace_id' => $workspace->getKey()]);
        $delivery = WebhookDelivery::factory()->create(['webhook_id' => $webhook->getKey()]);

        return [
            'workspace' => $workspace,
            'member' => $member,
            'provider' => $provider,
            'policy' => $policy,
            'automation' => $automation,
            'template' => $template,
            'setting' => $setting,
            'run' => $run,
            'audit' => $audit,
            'delivery' => $delivery,
        ];
    }
}
