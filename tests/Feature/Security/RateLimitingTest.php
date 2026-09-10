<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\WorkspaceRole;
use App\Livewire\App\Ai\Index as AiWorkspace;
use App\Livewire\App\Shared\CommandPalette;
use App\Models\AiProvider;
use App\Models\AiRun;
use App\Models\AiSetting;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\RateLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The request budgets outside `/api/v1` (docs/SECURITY.md §5.2).
 *
 * Login and the API were throttled from the first release; ordinary signed-in page traffic
 * was not, and neither were the four operations that cost real work. Every limit here is
 * deliberately generous — the numbers in `config/planvio.php` are set so that nothing a
 * person does can reach them — which is exactly why they need a test: a limiter nobody can
 * hit in normal use is a limiter that can silently stop existing.
 *
 * The limits are lowered to single digits for these tests. What is being asserted is that
 * the limiter is wired to the right routes and keyed on the right thing, not what the
 * shipped number happens to be.
 */
final class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
        $this->project = $this->makeProject($this->workspace, [], ['slug' => 'website', 'key' => 'WEB']);
    }

    /* ------------------------------------------------------------------ *
     * The web group
     * ------------------------------------------------------------------ */

    #[Test]
    public function signed_in_page_requests_are_limited(): void
    {
        config(['planvio.security.rate_limits.web' => 3]);

        $url = route('app.home', $this->workspace);

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->member)->get($url)->assertOk();
        }

        $this->actingAs($this->member)->get($url)->assertStatus(429);
    }

    /**
     * The key is the account, not the address. Every test request comes from 127.0.0.1, so
     * a limiter keyed on the address would refuse the second person here.
     */
    #[Test]
    public function one_persons_budget_is_not_another_persons(): void
    {
        config(['planvio.security.rate_limits.web' => 2]);

        $colleague = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $url = route('app.home', $this->workspace);

        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs($this->member)->get($url)->assertStatus(429);

        $this->actingAs($colleague)->get($url)->assertOk();
    }

    #[Test]
    public function signed_out_traffic_has_its_own_budget(): void
    {
        config(['planvio.security.rate_limits.guest' => 2, 'planvio.security.rate_limits.web' => 500]);

        $this->get(route('login'))->assertOk();
        $this->get(route('login'))->assertOk();
        $this->get(route('login'))->assertStatus(429);

        // The signed-in bucket is untouched by the guest one.
        $this->actingAs($this->member)->get(route('app.home', $this->workspace))->assertOk();
    }

    /* ------------------------------------------------------------------ *
     * The expensive endpoints
     * ------------------------------------------------------------------ */

    #[Test]
    public function exports_are_limited_more_tightly_than_pages(): void
    {
        config([
            'planvio.security.rate_limits.web' => 500,
            'planvio.security.rate_limits.exports' => 2,
        ]);

        $url = route('app.export.download', [$this->workspace, 'tasks']);

        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs($this->member)->get($url)->assertStatus(429);

        // Browsing is unaffected: the two budgets are separate counters.
        $this->actingAs($this->member)->get(route('app.export', $this->workspace))->assertOk();
    }

    #[Test]
    public function the_printable_status_report_is_limited(): void
    {
        config([
            'planvio.security.rate_limits.web' => 500,
            'planvio.security.rate_limits.reports' => 1,
        ]);

        $url = route('app.projects.report', [$this->workspace, $this->project]);

        $this->actingAs($this->member)->get($url)->assertOk();
        $this->actingAs($this->member)->get($url)->assertStatus(429);
    }

    #[Test]
    public function api_search_is_limited_below_the_token_budget(): void
    {
        config(['planvio.security.rate_limits.search' => 2]);

        $token = $this->member->createToken('test')->plainTextToken;

        $headers = [
            'Authorization' => 'Bearer '.$token,
            'X-Planvio-Workspace' => (string) $this->workspace->slug,
            'Accept' => 'application/json',
        ];

        $this->getJson(route('api.v1.search').'?q=web', $headers)->assertOk();
        $this->getJson(route('api.v1.search').'?q=web', $headers)->assertOk();
        $this->getJson(route('api.v1.search').'?q=web', $headers)->assertStatus(429);

        // A different endpoint on the same token still answers: the search budget is its own.
        $this->getJson(route('api.v1.me'), $headers)->assertOk();
    }

    /* ------------------------------------------------------------------ *
     * The two that arrive on Livewire's shared endpoint
     * ------------------------------------------------------------------ */

    /**
     * A palette that throws is a broken palette. When the allowance is spent it stops
     * querying, says when it will resume, and keeps offering the command rows — which cost
     * nothing and are half of what the palette is for.
     */
    #[Test]
    public function the_command_palette_pauses_instead_of_failing(): void
    {
        config(['planvio.security.rate_limits.search' => 1]);

        $this->app->make(CurrentWorkspace::class)->set($this->workspace);

        $component = Livewire::actingAs($this->member)->test(CommandPalette::class);

        $component->set('query', 'website')->assertSet('searchPausedFor', 0);

        $component->set('query', 'websites')
            ->assertSee('Searching again in')
            ->assertSee(__('Actions'));

        $this->assertGreaterThan(0, $component->get('searchPausedFor'));

        // And it recovers on its own once the window passes.
        RateLimits::clear(RateLimits::SEARCH, 'user:'.$this->member->getKey());

        $component->set('query', 'website again')->assertSet('searchPausedFor', 0);
    }

    /**
     * The AI composer refuses the send in Planvio's own words and writes nothing. The
     * provider budget in `ai.limits` is a different control — it counts rows in `ai_runs`
     * and is about money; this one is the burst in front of it.
     */
    #[Test]
    public function the_ai_composer_refuses_a_burst_without_queueing_anything(): void
    {
        Queue::fake();

        config(['planvio.security.rate_limits.ai_runs' => 1]);

        AiSetting::factory()->create([
            'workspace_id' => $this->workspace->getKey(),
            'is_enabled' => true,
            'ai_provider_id' => AiProvider::factory()->active()->create()->getKey(),
        ]);

        $this->actingInWorkspace($this->member, $this->workspace);

        $component = Livewire::test(AiWorkspace::class, ['workspace' => $this->workspace])
            ->set('draft', 'Summarise the website project.')
            ->call('send');

        $this->assertSame(1, AiRun::withoutWorkspaceScope()->count());
        $component->assertSet('refusal', null);

        $component->set('draft', 'And the marketing one.')->call('send');

        $this->assertSame(1, AiRun::withoutWorkspaceScope()->count(), 'The burst limit did not hold.');
        $this->assertStringContainsString('lot of requests', (string) $component->get('refusal'));

        // The draft is kept, so nothing anybody typed is lost to a limiter.
        $component->assertSet('draft', 'And the marketing one.');
    }

    #[Test]
    public function a_bucket_refuses_once_its_allowance_is_spent(): void
    {
        config(['planvio.security.rate_limits.search' => 2]);

        $key = 'user:'.$this->member->getKey();

        $this->assertTrue(RateLimits::attempt(RateLimits::SEARCH, $key));
        $this->assertTrue(RateLimits::attempt(RateLimits::SEARCH, $key));
        $this->assertFalse(RateLimits::attempt(RateLimits::SEARCH, $key));

        $this->assertGreaterThan(0, RateLimits::availableIn(RateLimits::SEARCH, $key));
    }

    #[Test]
    public function buckets_do_not_bleed_into_each_other(): void
    {
        config([
            'planvio.security.rate_limits.search' => 1,
            'planvio.security.rate_limits.ai_runs' => 1,
        ]);

        $key = 'user:'.$this->member->getKey();

        $this->assertTrue(RateLimits::attempt(RateLimits::SEARCH, $key));
        $this->assertFalse(RateLimits::attempt(RateLimits::SEARCH, $key));

        $this->assertTrue(RateLimits::attempt(RateLimits::AI_RUNS, $key));
    }

    /**
     * A refused attempt must not charge the bucket again. Otherwise a tab that keeps
     * retrying extends its own lockout, and the person who closed it an hour ago is still
     * refused when they come back.
     */
    #[Test]
    public function a_refusal_never_pushes_the_one_minute_window_further_out(): void
    {
        config(['planvio.security.rate_limits.ai_runs' => 1]);

        $key = 'user:'.$this->member->getKey();

        $this->assertTrue(RateLimits::attempt(RateLimits::AI_RUNS, $key));

        for ($i = 0; $i < 30; $i++) {
            $this->assertFalse(RateLimits::attempt(RateLimits::AI_RUNS, $key));
        }

        $this->assertLessThanOrEqual(60, RateLimits::availableIn(RateLimits::AI_RUNS, $key));
    }

    /**
     * A hand-edited zero would refuse every request. A limit is a limit, not a lockout.
     */
    #[Test]
    public function a_nonsensical_configured_limit_falls_back_rather_than_locking_out(): void
    {
        config(['planvio.security.rate_limits.web' => 0]);
        $this->assertSame(1, RateLimits::perMinute(RateLimits::WEB));

        config(['planvio.security.rate_limits.web' => 'nonsense']);
        $this->assertSame(300, RateLimits::perMinute(RateLimits::WEB));

        config(['planvio.security.rate_limits.web' => null]);
        $this->assertSame(300, RateLimits::perMinute(RateLimits::WEB));
    }

    /**
     * The shipped numbers, so that lowering one by accident is a failing test rather than a
     * quiet change in what the product does under load.
     */
    #[Test]
    public function the_shipped_limits_are_generous(): void
    {
        $this->assertSame(300, RateLimits::perMinute(RateLimits::WEB));
        $this->assertSame(120, RateLimits::perMinute(RateLimits::GUEST));
        $this->assertSame(60, RateLimits::perMinute(RateLimits::SEARCH));
        $this->assertSame(10, RateLimits::perMinute(RateLimits::AI_RUNS));
        $this->assertSame(10, RateLimits::perMinute(RateLimits::EXPORTS));
        $this->assertSame(20, RateLimits::perMinute(RateLimits::REPORTS));
    }
}
