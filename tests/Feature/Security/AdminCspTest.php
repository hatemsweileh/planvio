<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\WorkspaceRole;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Models\Attachment;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Content-Security-Policy on the administration panel (docs/SECURITY.md §5.1).
 *
 * `/admin` is a Filament panel, and a Filament panel assembles its own middleware stack
 * instead of running through the `web` group. Everything appended there globally — the
 * install gate, maintenance mode, the locale, and until now the policy — has to be named in
 * AdminPanelProvider as well, or it does not apply. That made the panel the one surface in
 * Planvio with no policy at all, which is the wrong surface to leave uncovered: it is where
 * AI provider credentials are entered.
 *
 * Three things are asserted here, and the third is the one that will actually catch a
 * regression.
 *
 * The header is on the panel, on every kind of screen it serves and on the redirect it
 * gives a visitor who is not signed in. It is configurable — on its own, or with the
 * application, in report-only or not at all.
 *
 * And nothing the panel renders is loaded from another origin. That is not a CSP assertion
 * so much as the condition that makes the policy safe to enforce: Filament ships with a
 * font provider that fetches from fonts.bunny.net and an avatar provider that asks
 * ui-avatars.com to draw the signed-in administrator's initials. Both are switched off in
 * AdminPanelProvider — an outbound request per screen is wrong on a self-hosted product and
 * broken on an installation with no internet access — and if either comes back, the policy
 * starts blocking it and the panel starts looking broken to the person best placed to turn
 * the policy off.
 */
final class AdminCspTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);

        // A platform administrator who is also a member of a workspace: the panel and the
        // product are the same session, which is what the attachment assertion below needs.
        $this->admin = $this->makeMember($this->workspace, WorkspaceRole::Owner, [
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    /* ------------------------------------------------------------------ *
     * It is sent
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_panel_carries_the_policy(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin');

        $response->assertOk();
        $response->assertHeader(
            'Content-Security-Policy',
            ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL),
        );
    }

    /**
     * A dashboard, a resource listing, a form, and the screen that holds provider
     * credentials — the four shapes of page the panel serves.
     *
     * @return array<string, array{0: string}>
     */
    public static function panelScreens(): array
    {
        return [
            'dashboard' => ['/admin'],
            'resource listing' => ['/admin/users'],
            'form' => ['/admin/users/create'],
            'AI provider' => ['/admin/ai-providers/create'],
        ];
    }

    #[Test]
    #[DataProvider('panelScreens')]
    public function every_kind_of_panel_screen_carries_it(string $url): void
    {
        $response = $this->actingAs($this->admin)->get($url);

        $response->assertOk();
        $response->assertHeader(
            'Content-Security-Policy',
            ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL),
        );
    }

    /**
     * The middleware is first in the panel stack so that it wraps what the rest of the stack
     * produces, not only the pages that get as far as rendering.
     */
    #[Test]
    public function the_redirect_a_signed_out_visitor_gets_carries_it_too(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect(route('login'));
        $response->assertHeader(
            'Content-Security-Policy',
            ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL),
        );
    }

    /* ------------------------------------------------------------------ *
     * The stricter policy still wins
     * ------------------------------------------------------------------ */

    /**
     * An attachment is bytes somebody uploaded, and AttachmentController sends
     * `default-src 'none'; sandbox` with it. Neither policy may replace that — including on
     * a session that was established in the panel, which is the case this test exists for.
     */
    #[Test]
    public function an_attachment_fetched_from_a_panel_session_keeps_the_sandbox(): void
    {
        $attachment = $this->attachment();

        $this->actingAs($this->admin)->get('/admin')->assertOk();

        $response = $this->get(route('attachments.download', $attachment));

        $response->assertOk();
        $response->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; img-src 'self' data:; object-src 'none'; sandbox",
        );

        $this->assertStringNotContainsString(
            'unsafe-eval',
            (string) $response->headers->get('Content-Security-Policy'),
            'A panel policy replaced the attachment policy. Uploaded bytes would be able to '
            .'run script in this origin.',
        );
    }

    #[Test]
    public function report_only_on_the_panel_cannot_downgrade_the_attachment_policy(): void
    {
        config(['planvio.security.csp.panel.report_only' => true]);

        $attachment = $this->attachment();

        $this->actingAs($this->admin)->get('/admin')->assertOk();

        $response = $this->get(route('attachments.download', $attachment));

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $response->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; img-src 'self' data:; object-src 'none'; sandbox",
        );
    }

    /* ------------------------------------------------------------------ *
     * Configuration
     * ------------------------------------------------------------------ */

    #[Test]
    public function report_only_moves_the_panel_policy_to_the_report_only_header(): void
    {
        config(['planvio.security.csp.panel.report_only' => true]);

        $response = $this->actingAs($this->admin)->get('/admin');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader(
            'Content-Security-Policy-Report-Only',
            ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL),
        );
    }

    /**
     * The panel block is an override, not a separate policy: with nothing set there — how it
     * ships — PLANVIO_CSP_REPORT_ONLY covers both surfaces. An administrator trying a change
     * safely should not have to know that `/admin` is assembled differently.
     */
    #[Test]
    public function the_panel_follows_the_application_when_it_has_no_setting_of_its_own(): void
    {
        config([
            'planvio.security.csp.report_only' => true,
            'planvio.security.csp.panel.report_only' => null,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('Content-Security-Policy-Report-Only', ContentSecurityPolicy::policy());
    }

    #[Test]
    public function the_panel_setting_wins_over_the_application_one(): void
    {
        config([
            'planvio.security.csp.report_only' => true,
            'planvio.security.csp.panel.report_only' => false,
        ]);

        $response = $this->actingAs($this->admin)->get('/admin');

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        $response->assertHeader(
            'Content-Security-Policy',
            ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL),
        );
    }

    #[Test]
    public function turning_the_application_policy_off_turns_the_panel_off_too(): void
    {
        config(['planvio.security.csp.enabled' => false]);

        $response = $this->actingAs($this->admin)->get('/admin');

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    #[Test]
    public function the_panel_can_be_switched_off_without_touching_the_application(): void
    {
        config(['planvio.security.csp.panel.enabled' => false]);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertHeaderMissing('Content-Security-Policy');

        $this->actingAs($this->admin)
            ->get(route('app.home', $this->workspace))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', ContentSecurityPolicy::policy());
    }

    #[Test]
    public function an_extra_directive_set_for_the_panel_applies_only_to_the_panel(): void
    {
        config([
            'planvio.security.csp.extra_directives' => ['frame-ancestors' => "'none'"],
            'planvio.security.csp.panel.extra_directives' => ['connect-src' => "'self' https://example.test"],
        ]);

        $panel = ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL);

        $this->assertStringContainsString("connect-src 'self' https://example.test", $panel);
        $this->assertStringContainsString("frame-ancestors 'self'", $panel);

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertHeader('Content-Security-Policy', $panel);

        // The application keeps its own, and the panel's addition does not leak into it.
        $application = ContentSecurityPolicy::policy();

        $this->assertStringContainsString("frame-ancestors 'none'", $application);
        $this->assertStringNotContainsString('example.test', $application);
    }

    #[Test]
    public function the_panel_inherits_the_application_directives_when_it_names_none(): void
    {
        config(['planvio.security.csp.extra_directives' => ['object-src' => "'self'"]]);

        $this->assertStringContainsString(
            "object-src 'self'",
            ContentSecurityPolicy::policy(ContentSecurityPolicy::PANEL),
        );
    }

    /**
     * A profile name that is neither of the two must not produce a page with no policy.
     */
    #[Test]
    public function an_unknown_profile_falls_back_to_the_application_policy(): void
    {
        $this->assertSame(ContentSecurityPolicy::policy(), ContentSecurityPolicy::policy('nonsense'));
        $this->assertSame('Content-Security-Policy', ContentSecurityPolicy::header('nonsense'));
    }

    /* ------------------------------------------------------------------ *
     * Nothing the panel loads is off-origin
     * ------------------------------------------------------------------ */

    #[Test]
    #[DataProvider('panelScreens')]
    public function no_panel_screen_asks_another_origin_for_anything(string $url): void
    {
        $html = (string) $this->actingAs($this->admin)->get($url)->assertOk()->getContent();

        $origin = rtrim((string) config('app.url'), '/');

        preg_match_all('#(?:src|href|action)="(https?://[^"]+)"#i', $html, $matches);

        $offOrigin = array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $found): bool => ! str_starts_with($found, $origin.'/'),
        )));

        $this->assertSame(
            [],
            $offOrigin,
            $url.' loads something from another origin. Under the panel policy the browser '
            .'refuses it, and the screen breaks for the one person who can switch the policy '
            .'off. Serve it from this installation instead.',
        );
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function attachment(): Attachment
    {
        $project = $this->makeProject($this->workspace);
        $task = $this->makeTask($project);

        $path = 'attachments/'.$this->workspace->getKey().'/brief.pdf';

        Storage::disk('private')->put($path, '%PDF-1.7 brief');

        return Attachment::factory()
            ->on($task)
            ->create([
                'workspace_id' => $this->workspace->getKey(),
                'uploaded_by' => $this->admin->getKey(),
                'disk' => 'private',
                'path' => $path,
                'original_name' => 'brief.pdf',
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => 14,
            ]);
    }
}
