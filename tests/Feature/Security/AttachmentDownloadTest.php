<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Enums\WorkspaceRole;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * The attachment download route (ARCHITECTURE.md §9).
 *
 * Uploads are the one place a user puts bytes of their choosing on the server, and this
 * route is the only way those bytes come back out. Three separate things have to hold, and
 * each of them is asserted here rather than assumed:
 *
 *   1. **It authorises.** Somebody outside the workspace gets nothing — not a redirect to
 *      the file, not an empty 200, nothing.
 *   2. **It streams.** The response is the file. A redirect to a storage URL would hand out
 *      a link that outlives the authorisation check.
 *   3. **It cannot execute in this origin.** Anything that a browser might render as a
 *      document — an uploaded `.html`, an `.svg` carrying script — comes back as a download
 *      with a flattened content type, so a stored file can never become stored XSS with a
 *      live session attached.
 */
final class AttachmentDownloadTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    private Project $project;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->workspace = $this->makeWorkspace();
        $this->member = $this->makeMember($this->workspace, WorkspaceRole::Member);
        $this->project = $this->makeProject($this->workspace);
        $this->task = $this->makeTask($this->project);
    }

    /* ------------------------------------------------------------------ *
     * Authorisation
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_member_of_the_workspace_gets_the_file(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', '%PDF-1.7 brief');

        $response = $this->actingAs($this->member)
            ->get(route('attachments.download', $attachment));

        $response->assertOk();

        $this->assertSame('%PDF-1.7 brief', $response->streamedContent());
    }

    #[Test]
    public function somebody_from_another_workspace_is_refused(): void
    {
        $attachment = $this->storeAttachment('secret.pdf', 'application/pdf', 'confidential');

        $outsider = $this->makeMember($this->makeWorkspace());

        $this->assertDeniedAccess($outsider, route('attachments.download', $attachment));
    }

    #[Test]
    public function an_account_with_no_workspace_at_all_is_refused(): void
    {
        $attachment = $this->storeAttachment('secret.pdf', 'application/pdf', 'confidential');

        $stranger = User::factory()->create();

        $this->assertDeniedAccess($stranger, route('attachments.download', $attachment));
    }

    #[Test]
    public function a_refused_request_returns_no_bytes(): void
    {
        $attachment = $this->storeAttachment('secret.pdf', 'application/pdf', 'confidential');

        $outsider = $this->makeMember($this->makeWorkspace());

        $response = $this->actingAs($outsider)->get(route('attachments.download', $attachment));

        $this->assertContains($response->getStatusCode(), [403, 404]);
        $this->assertStringNotContainsString('confidential', $response->getContent() ?: '');
    }

    #[Test]
    public function a_guest_is_sent_to_sign_in_rather_than_to_the_file(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'body');

        $this->get(route('attachments.download', $attachment))->assertRedirect(route('login'));
    }

    /* ------------------------------------------------------------------ *
     * It streams; it never redirects
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_response_is_a_stream_and_not_a_redirect(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'body');

        $response = $this->actingAs($this->member)->get(route('attachments.download', $attachment));

        $response->assertOk();

        $this->assertInstanceOf(
            StreamedResponse::class,
            $response->baseResponse,
            'The download must stream from the private disk, never redirect to a storage URL.',
        );

        $this->assertFalse($response->baseResponse->isRedirection());
        $this->assertNull($response->headers->get('Location'));
    }

    #[Test]
    public function the_stored_path_is_never_disclosed(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'body');

        $response = $this->actingAs($this->member)->get(route('attachments.download', $attachment));
        $headers = implode("\n", array_map(
            static fn (array $values): string => implode(' ', $values),
            $response->headers->all(),
        ));

        $this->assertStringNotContainsString($attachment->path, $headers);
        $this->assertStringNotContainsString('storage/app', $headers);
        $this->assertStringNotContainsString($attachment->path, $response->streamedContent());
    }

    /* ------------------------------------------------------------------ *
     * Nothing stored may execute in this origin
     * ------------------------------------------------------------------ */

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function dangerousFiles(): array
    {
        return [
            'html' => ['page.html', 'text/html', '<script>alert(document.cookie)</script>'],
            'svg' => ['logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>'],
            'xml' => ['feed.xml', 'application/xml', '<?xml version="1.0"?><a/>'],
            'json' => ['data.json', 'application/json', '{"a":1}'],
            'csv' => ['rows.csv', 'text/csv', "a,b\n1,2"],
        ];
    }

    #[Test]
    #[DataProvider('dangerousFiles')]
    public function anything_a_browser_might_render_is_forced_to_download(
        string $name,
        string $mime,
        string $body,
    ): void {
        $attachment = $this->storeAttachment($name, $mime, $body);

        $response = $this->actingAs($this->member)->get(route('attachments.download', $attachment));

        $response->assertOk();

        $this->assertStringStartsWith(
            'attachment;',
            (string) $response->headers->get('Content-Disposition'),
            $name.' must never be served inline: it would run in the application origin.',
        );

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function inlineFiles(): array
    {
        return [
            'png' => ['shot.png', 'image/png'],
            'jpeg' => ['photo.jpg', 'image/jpeg'],
            'pdf' => ['brief.pdf', 'application/pdf'],
        ];
    }

    #[Test]
    #[DataProvider('inlineFiles')]
    public function an_inert_preview_type_is_shown_in_place(string $name, string $mime): void
    {
        $attachment = $this->storeAttachment($name, $mime, 'bytes');

        $response = $this->actingAs($this->member)->get(route('attachments.download', $attachment));

        $response->assertOk();
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame($mime, $response->headers->get('Content-Type'));
    }

    #[Test]
    public function an_explicit_download_request_overrides_the_inline_preview(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'bytes');

        $response = $this->actingAs($this->member)
            ->get(route('attachments.download', $attachment).'?download=1');

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
    }

    #[Test]
    public function a_filename_that_tries_to_escape_the_header_is_stripped(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'bytes');
        $attachment->forceFill(['original_name' => "../../etc/passwd\r\nX-Injected: 1"])->save();

        $response = $this->actingAs($this->member)->get(route('attachments.download', $attachment));

        $response->assertOk();

        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringNotContainsString('..', $disposition);
        $this->assertStringNotContainsString('/', $disposition);
        $this->assertNull($response->headers->get('X-Injected'));
    }

    /* ------------------------------------------------------------------ *
     * Missing and mis-pointed records
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_record_whose_bytes_are_gone_is_a_not_found(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'bytes');

        Storage::disk('private')->delete($attachment->path);

        $this->actingAs($this->member)
            ->get(route('attachments.download', $attachment))
            ->assertNotFound();
    }

    #[Test]
    public function a_record_pointing_at_an_unconfigured_disk_falls_back_to_the_private_one(): void
    {
        $attachment = $this->storeAttachment('brief.pdf', 'application/pdf', 'bytes');
        $attachment->forceFill(['disk' => 'not-a-real-disk'])->save();

        $this->actingAs($this->member)
            ->get(route('attachments.download', $attachment))
            ->assertOk();
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function storeAttachment(string $name, string $mime, string $body): Attachment
    {
        $path = 'attachments/'.$this->workspace->getKey().'/'.uniqid('', true).'/'.$name;

        Storage::disk('private')->put($path, $body);

        return Attachment::factory()
            ->on($this->task)
            ->create([
                'workspace_id' => $this->workspace->getKey(),
                'uploaded_by' => $this->member->getKey(),
                'disk' => 'private',
                'path' => $path,
                'original_name' => $name,
                'mime' => $mime,
                'extension' => pathinfo($name, PATHINFO_EXTENSION),
                'size_bytes' => strlen($body),
            ]);
    }
}
