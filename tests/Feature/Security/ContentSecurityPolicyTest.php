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
 * The application Content-Security-Policy (docs/SECURITY.md §5.1).
 *
 * Two properties are asserted and they pull in opposite directions.
 *
 * The header has to be *there*, on signed-in pages and signed-out ones, with the exact
 * directives the documentation claims — a policy that quietly stopped being sent would
 * leave SECURITY.md making a promise the product no longer keeps.
 *
 * And it has to keep its hands off the one response that already has a stricter policy.
 * The attachment route sits inside the `web` group, so the loose application policy would
 * otherwise replace `default-src 'none'; sandbox` on exactly the response made of bytes
 * somebody uploaded. That is the assertion this file exists for.
 */
final class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->workspace = $this->makeWorkspace(['slug' => 'acme']);
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Owner);
    }

    /* ------------------------------------------------------------------ *
     * It is sent
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_signed_in_page_carries_the_policy(): void
    {
        $response = $this->actingAs($this->member)->get(route('app.home', $this->workspace));

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy', ContentSecurityPolicy::policy());
    }

    #[Test]
    public function a_signed_out_page_carries_it_too(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertHeader('Content-Security-Policy', ContentSecurityPolicy::policy());
    }

    /**
     * The directives named in docs/SECURITY.md, each asserted on its own so a failure says
     * which one moved.
     */
    #[Test]
    #[DataProvider('shippedDirectives')]
    public function the_policy_contains(string $directive): void
    {
        $this->assertStringContainsString($directive, ContentSecurityPolicy::policy());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function shippedDirectives(): array
    {
        return [
            'default-src' => ["default-src 'self'"],
            'img-src' => ["img-src 'self' data: blob:"],
            'font-src' => ["font-src 'self'"],
            'style-src' => ["style-src 'self' 'unsafe-inline'"],
            'script-src' => ["script-src 'self' 'unsafe-eval' 'unsafe-inline'"],
            'connect-src' => ["connect-src 'self'"],
            'frame-ancestors' => ["frame-ancestors 'self'"],
            'base-uri' => ["base-uri 'self'"],
            'form-action' => ["form-action 'self'"],
            'object-src' => ["object-src 'none'"],
        ];
    }

    /* ------------------------------------------------------------------ *
     * The stricter policy still wins
     * ------------------------------------------------------------------ */

    #[Test]
    public function an_attachment_download_keeps_its_own_much_stricter_policy(): void
    {
        $attachment = $this->attachment();

        $response = $this->actingAs($this->member)
            ->get(route('attachments.download', $attachment));

        $response->assertOk();
        $response->assertHeader(
            'Content-Security-Policy',
            "default-src 'none'; img-src 'self' data:; object-src 'none'; sandbox",
        );

        $this->assertStringNotContainsString(
            'unsafe-eval',
            (string) $response->headers->get('Content-Security-Policy'),
            'The application policy replaced the attachment policy. Uploaded bytes would be '
            .'able to run script in this origin.',
        );
    }

    #[Test]
    public function the_report_only_switch_does_not_let_the_attachment_policy_be_downgraded(): void
    {
        config(['planvio.security.csp.report_only' => true]);

        $response = $this->actingAs($this->member)
            ->get(route('attachments.download', $this->attachment()));

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
    public function report_only_moves_the_policy_to_the_report_only_header(): void
    {
        config(['planvio.security.csp.report_only' => true]);

        $response = $this->actingAs($this->member)->get(route('app.home', $this->workspace));

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeader('Content-Security-Policy-Report-Only', ContentSecurityPolicy::policy());
    }

    #[Test]
    public function turning_it_off_sends_no_policy_at_all(): void
    {
        config(['planvio.security.csp.enabled' => false]);

        $response = $this->actingAs($this->member)->get(route('app.home', $this->workspace));

        $response->assertOk();
        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    #[Test]
    public function an_extra_directive_replaces_a_shipped_one(): void
    {
        config(['planvio.security.csp.extra_directives' => ['script-src' => "'self'"]]);

        $policy = ContentSecurityPolicy::policy();

        $this->assertStringContainsString("script-src 'self';", $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
    }

    #[Test]
    public function an_extra_directive_can_add_one_that_is_not_shipped(): void
    {
        config(['planvio.security.csp.extra_directives' => ['frame-src' => "'none'"]]);

        $this->assertStringContainsString("frame-src 'none'", ContentSecurityPolicy::policy());
    }

    #[Test]
    public function an_empty_extra_directive_removes_it(): void
    {
        config(['planvio.security.csp.extra_directives' => ['font-src' => '']]);

        $this->assertStringNotContainsString('font-src', ContentSecurityPolicy::policy());
    }

    /**
     * A directive value carrying its own semicolon would end the directive early and let one
     * setting smuggle in another — including a `script-src` an administrator never wrote.
     */
    #[Test]
    public function a_semicolon_in_a_configured_value_cannot_open_a_second_directive(): void
    {
        config([
            'planvio.security.csp.extra_directives' => [
                'connect-src' => "'self'; script-src *",
            ],
        ]);

        $policy = ContentSecurityPolicy::policy();

        // Flattened into the directive it was written in, never promoted to its own.
        $this->assertStringNotContainsString('; script-src *', $policy);
        $this->assertStringContainsString("connect-src 'self' script-src *", $policy);
    }

    #[Test]
    public function a_directive_name_that_is_not_one_is_ignored(): void
    {
        config([
            'planvio.security.csp.extra_directives' => [
                "bad name\nX-Injected" => 'value',
                '' => "'none'",
            ],
        ]);

        $policy = ContentSecurityPolicy::policy();

        $this->assertStringNotContainsString('X-Injected', $policy);
        $this->assertSame(10, substr_count($policy, '; ') + 1);
    }

    #[Test]
    public function a_directive_name_is_matched_without_regard_to_case(): void
    {
        config(['planvio.security.csp.extra_directives' => ['Object-Src' => "'self'"]]);

        $policy = ContentSecurityPolicy::policy();

        $this->assertStringContainsString("object-src 'self'", $policy);
        $this->assertStringNotContainsString("object-src 'none'", $policy);
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
                'uploaded_by' => $this->member->getKey(),
                'disk' => 'private',
                'path' => $path,
                'original_name' => 'brief.pdf',
                'mime' => 'application/pdf',
                'extension' => 'pdf',
                'size_bytes' => 14,
            ]);
    }
}
