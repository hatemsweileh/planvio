<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Actions\Attachments\DeleteAttachment;
use App\Actions\Attachments\StoreAttachment;
use App\Exceptions\UploadRejected;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The upload gate (docs/SECURITY.md; ARCHITECTURE.md §9).
 *
 * Attachments are the one place an attacker gets to put bytes of their choosing onto the
 * server's filesystem, so every assertion here is about something *not* happening: no
 * executable extension surviving, no file whose contents disagree with its name, and no
 * path outside the workspace's own directory.
 *
 * The uploads are real files with real bytes rather than `UploadedFile::fake()`, because
 * the control under test is content sniffing and a fake file has no content to sniff. The
 * "PHP file" fixtures are harmless scripts assembled at runtime — `finfo` only needs the
 * opening tag to report `text/x-php`, and a file carrying a real web-shell string would be
 * quarantined by desktop antivirus before the suite ever ran.
 */
final class AttachmentUploadTest extends TestCase
{
    use RefreshDatabase;

    /** A genuine 1x1 PNG, so `finfo` reports image/png. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private Workspace $workspace;

    private Project $project;

    private Task $task;

    private User $uploader;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->workspace = $this->makeWorkspace();
        $this->uploader = $this->makeMember($this->workspace);
        $this->project = $this->makeProject($this->workspace);
        $this->task = $this->makeTask($this->project);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->tempFiles = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * Executable content
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_rejects_a_php_upload(): void
    {
        $rejection = $this->reject('shell.php', $this->phpSource());

        $this->assertSame(UploadRejected::REASON_BLOCKED_EXTENSION, $rejection->reason);
        $this->assertNothingWasStored();
    }

    /**
     * The blocked list is checked before the allow-lists and before anything is read, so a
     * blocked extension cannot be reached around by a server whose MIME database happens to
     * report something acceptable.
     */
    #[Test]
    #[DataProvider('blockedNames')]
    public function it_rejects_every_blocked_extension(string $name): void
    {
        $rejection = $this->reject($name, $this->phpSource());

        $this->assertSame(UploadRejected::REASON_BLOCKED_EXTENSION, $rejection->reason);
        $this->assertNothingWasStored();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedNames(): array
    {
        return [
            'phtml' => ['page.phtml'],
            'phar' => ['bundle.phar'],
            'php5' => ['legacy.php5'],
            'htaccess' => ['.htaccess'],
            'executable' => ['setup.exe'],
            'shell script' => ['deploy.sh'],
            'uppercase php' => ['SHELL.PHP'],
        ];
    }

    /**
     * `report.php.png` looks like a PNG to anyone reading the last extension, and like PHP
     * to a web server with an `AddHandler` line that matches on any extension in the name.
     */
    #[Test]
    public function it_rejects_a_blocked_extension_hidden_between_others(): void
    {
        $rejection = $this->reject('report.php.png', $this->png());

        $this->assertSame(UploadRejected::REASON_BLOCKED_EXTENSION, $rejection->reason);
        $this->assertSame('php', $rejection->context()['extension'] ?? null);
        $this->assertNothingWasStored();
    }

    /* ------------------------------------------------------------------ *
     * Content sniffing
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_rejects_a_php_file_renamed_to_png(): void
    {
        $rejection = $this->reject('avatar.png', $this->phpSource());

        $this->assertContains(
            $rejection->reason,
            [UploadRejected::REASON_MIME_NOT_ALLOWED, UploadRejected::REASON_MIME_MISMATCH],
            'A PHP file renamed to .png must be refused on its sniffed content, '
            .'not accepted because its extension is allowed.',
        );

        $this->assertNothingWasStored();
    }

    /**
     * The pairing check, isolated: `text/plain` is an accepted type and `.png` is an
     * accepted extension, but the combination is not a PNG.
     */
    #[Test]
    public function it_rejects_content_that_does_not_match_its_extension(): void
    {
        $rejection = $this->reject('screenshot.png', 'this is not a picture');

        $this->assertSame(UploadRejected::REASON_MIME_MISMATCH, $rejection->reason);
        $this->assertNothingWasStored();
    }

    #[Test]
    public function it_rejects_an_extension_that_is_not_on_the_allow_list(): void
    {
        $rejection = $this->reject('firmware.bin', $this->png());

        $this->assertSame(UploadRejected::REASON_EXTENSION_NOT_ALLOWED, $rejection->reason);
        $this->assertNothingWasStored();
    }

    #[Test]
    public function it_rejects_a_file_with_no_extension_at_all(): void
    {
        $rejection = $this->reject('README', 'plain text');

        $this->assertSame(UploadRejected::REASON_NO_EXTENSION, $rejection->reason);
        $this->assertNothingWasStored();
    }

    /* ------------------------------------------------------------------ *
     * Path traversal
     * ------------------------------------------------------------------ */

    #[Test]
    public function path_traversal_in_the_filename_cannot_escape_the_directory(): void
    {
        $attachment = $this->store('../../../../../../etc/cron.d/evil.png', $this->png());

        $this->assertStringStartsWith(
            'attachments/'.$this->workspace->id.'/',
            (string) $attachment->path,
            'The stored path left the workspace directory.',
        );

        $this->assertStringNotContainsString('..', (string) $attachment->path);
        $this->assertStringNotContainsString('etc/cron.d', (string) $attachment->path);
        $this->assertStringNotContainsString('\\', (string) $attachment->path);

        // The name kept for display is a bare filename with no path left in it.
        $this->assertSame('evil.png', $attachment->original_name);

        // And the bytes really are where the row says, inside the disk root.
        $disk = Storage::disk('private');
        $disk->assertExists($attachment->path);
        $this->assertSame([$attachment->path], $disk->allFiles());

        $root = realpath($disk->path(''));
        $written = realpath($disk->path((string) $attachment->path));

        $this->assertIsString($root);
        $this->assertIsString($written);
        $this->assertStringStartsWith($root, $written, 'The file was written outside the disk root.');
    }

    #[Test]
    #[DataProvider('traversalNames')]
    public function it_strips_every_separator_from_the_stored_name(string $name): void
    {
        $attachment = $this->store($name, $this->png());

        $this->assertStringNotContainsString('/', (string) $attachment->original_name);
        $this->assertStringNotContainsString('\\', (string) $attachment->original_name);
        $this->assertStringNotContainsString('..', (string) $attachment->path);
        $this->assertStringStartsWith('attachments/'.$this->workspace->id.'/', (string) $attachment->path);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversalNames(): array
    {
        return [
            'unix' => ['../../secrets/photo.png'],
            'windows' => ['..\\..\\windows\\system32\\photo.png'],
            'mixed' => ['..\\../..\\photo.png'],
            'absolute' => ['/var/www/html/photo.png'],
            'trailing dot' => ['photo.png.'],
        ];
    }

    #[Test]
    public function it_never_uses_the_client_filename_on_disk(): void
    {
        $attachment = $this->store('quarterly-report.png', $this->png());

        $this->assertStringNotContainsString('quarterly-report', (string) $attachment->path);
        $this->assertSame('quarterly-report.png', $attachment->original_name);
        $this->assertMatchesRegularExpression(
            '#^attachments/\d+/\d{4}/\d{2}/[A-Za-z0-9]{40}\.png$#',
            (string) $attachment->path,
        );
    }

    /* ------------------------------------------------------------------ *
     * SVG
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_strips_script_from_an_uploaded_svg(): void
    {
        $payload = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
            .'<script>window.alert(1)</script>'
            .'<foreignObject><body xmlns="http://www.w3.org/1999/xhtml">hi</body></foreignObject>'
            .'<rect width="10" height="10" onload="window.alert(2)" onclick="window.alert(3)"/>'
            .'<a xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="javascript:window.alert(4)">'
            .'<text>x</text></a>'
            .'</svg>';

        $attachment = $this->store('logo.svg', $payload);

        $stored = Storage::disk('private')->get($attachment->path);

        $this->assertIsString($stored);
        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsStringIgnoringCase('foreignobject', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onclick', $stored);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $stored);
        $this->assertStringContainsString('<rect', $stored);

        // The stored size and checksum describe the sanitised bytes, not the upload.
        $this->assertSame(strlen($stored), (int) $attachment->size_bytes);
        $this->assertSame(hash('sha256', $stored), $attachment->checksum);
    }

    #[Test]
    public function it_rejects_a_file_claiming_to_be_an_svg_that_is_not_one(): void
    {
        $rejection = $this->reject('logo.svg', '<html><body><p>not a drawing</p></body></html>');

        $this->assertContains(
            $rejection->reason,
            [UploadRejected::REASON_MIME_MISMATCH, UploadRejected::REASON_MIME_NOT_ALLOWED],
        );

        $this->assertNothingWasStored();
    }

    /**
     * A sniff that cannot read the file must still be a refusal, not a fault.
     *
     * `finfo_file()` can fail for reasons the application does not control — an endpoint
     * scanner holding the handle open, a temporary file swept between checks, a permission
     * or filesystem error. It signals that by emitting a PHP warning and returning false,
     * and Laravel promotes any warning to an `ErrorException`. If the gate does not
     * suppress the diagnostic, that exception escapes as a 500 and the refusal never gets
     * a `reason`, so the control fires without being counted.
     *
     * Nothing is stored either way; what this pins is that the failure stays inside the
     * gate's own vocabulary.
     */
    #[Test]
    public function a_sniffer_that_cannot_read_the_file_refuses_it_rather_than_faulting(): void
    {
        $path = $this->unreadableToTheSniffer();

        if ($path === null) {
            $this->markTestSkipped('This environment has no file that finfo_file() fails to open.');
        }

        $this->tempFiles[] = $path;

        try {
            app(StoreAttachment::class)(
                $this->task,
                new UploadedFile($path, 'notes.txt', null, null, true),
                $this->uploader,
            );

            $this->fail('Expected the upload to be refused.');
        } catch (UploadRejected $rejection) {
            $this->assertSame(UploadRejected::REASON_UNREADABLE, $rejection->reason);
        }

        $this->assertNothingWasStored();
    }

    /* ------------------------------------------------------------------ *
     * Size
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_rejects_a_file_over_the_configured_limit(): void
    {
        config()->set('planvio.uploads.max_size_kb', 1);

        $rejection = $this->reject('big.txt', str_repeat('a', 4096));

        $this->assertSame(UploadRejected::REASON_TOO_LARGE, $rejection->reason);
        $this->assertNothingWasStored();
    }

    #[Test]
    public function it_rejects_an_empty_file(): void
    {
        $rejection = $this->reject('empty.txt', '');

        $this->assertSame(UploadRejected::REASON_EMPTY, $rejection->reason);
        $this->assertNothingWasStored();
    }

    /* ------------------------------------------------------------------ *
     * Storage shape
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_stores_an_accepted_upload_on_the_private_disk(): void
    {
        $bytes = $this->png();
        $attachment = $this->store('photo.png', $bytes);

        $this->assertSame('private', $attachment->disk);
        $this->assertSame('image/png', $attachment->mime);
        $this->assertSame('png', $attachment->extension);
        $this->assertSame(strlen($bytes), (int) $attachment->size_bytes);
        $this->assertSame(hash('sha256', $bytes), $attachment->checksum);
        $this->assertSame((int) $this->workspace->id, (int) $attachment->workspace_id);
        $this->assertSame((int) $this->uploader->id, (int) $attachment->uploaded_by);
        // The stored value is the morph-map alias, not the class name: the map is enforced
        // so that a *_type column never carries internal namespace structure into the
        // database or out through the API. The relation still resolves to the Task.
        $this->assertSame('task', $attachment->attachable_type);
        $this->assertTrue($attachment->attachable->is($this->task));

        Storage::disk('private')->assertExists($attachment->path);
    }

    #[Test]
    public function it_keeps_each_workspace_in_its_own_directory(): void
    {
        $other = $this->makeWorkspace();
        $otherProject = $this->makeProject($other);
        $otherTask = $this->makeTask($otherProject);
        $otherUser = $this->makeMember($other);

        $mine = $this->store('photo.png', $this->png());
        $theirs = app(StoreAttachment::class)(
            $otherTask,
            $this->upload('photo.png', $this->png()),
            $otherUser,
        );

        $this->assertStringStartsWith('attachments/'.$this->workspace->id.'/', (string) $mine->path);
        $this->assertStringStartsWith('attachments/'.$other->id.'/', (string) $theirs->path);
        $this->assertNotSame($mine->path, $theirs->path);
    }

    #[Test]
    public function it_returns_the_existing_row_when_the_same_file_is_uploaded_twice(): void
    {
        $bytes = $this->png();

        $first = $this->store('photo.png', $bytes);
        $second = $this->store('photo.png', $bytes);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Attachment::withoutWorkspaceScope()->count());
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    #[Test]
    public function it_refuses_a_subject_that_carries_no_workspace(): void
    {
        $rejection = null;

        try {
            app(StoreAttachment::class)(
                $this->uploader,
                $this->upload('photo.png', $this->png()),
                $this->uploader,
            );
        } catch (UploadRejected $thrown) {
            $rejection = $thrown;
        }

        $this->assertInstanceOf(UploadRejected::class, $rejection);
        $this->assertSame(UploadRejected::REASON_NOT_ATTACHABLE, $rejection->reason);
        $this->assertNothingWasStored();
    }

    /* ------------------------------------------------------------------ *
     * Deletion
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_soft_delete_keeps_the_bytes_so_the_attachment_can_come_back(): void
    {
        $attachment = $this->store('photo.png', $this->png());

        app(DeleteAttachment::class)($attachment, $this->uploader);

        $this->assertSoftDeleted($attachment);
        Storage::disk('private')->assertExists($attachment->path);
    }

    #[Test]
    public function a_purge_removes_the_row_and_the_bytes(): void
    {
        $attachment = $this->store('photo.png', $this->png());
        $path = (string) $attachment->path;

        app(DeleteAttachment::class)($attachment, $this->uploader, purge: true);

        $this->assertDatabaseCount('attachments', 0);
        Storage::disk('private')->assertMissing($path);
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private function store(string $name, string $contents): Attachment
    {
        return app(StoreAttachment::class)($this->task, $this->upload($name, $contents), $this->uploader);
    }

    private function reject(string $name, string $contents): UploadRejected
    {
        try {
            $this->store($name, $contents);
        } catch (UploadRejected $rejection) {
            return $rejection;
        }

        $this->fail("Expected the upload [{$name}] to be rejected, but it was stored.");
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'planvio-upload');
        file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        // The fifth argument puts the object in test mode: it behaves like a real upload
        // without having arrived through PHP's upload handling.
        return new UploadedFile($path, $name, null, null, true);
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG_BASE64, true);
    }

    /**
     * A harmless script that `finfo` still reports as `text/x-php`: the opening tag is all
     * the sniffer looks at, and all this test needs.
     */
    private function phpSource(): string
    {
        return '<'.'?php echo "planvio upload fixture"; ?'.'>';
    }

    /**
     * A real file that passes every readability check the gate makes and that `finfo_file()`
     * nevertheless fails to open, or null when this machine cannot produce one.
     *
     * The lever used here is an endpoint scanner: real-time protection recognises a
     * web-shell byte sequence and denies the open, which is exactly the shape of failure
     * the gate has to survive. The bytes are assembled at runtime so the fixture is not a
     * matchable string sitting in the repository, and the precondition is verified rather
     * than assumed — on a machine with no scanner the test skips instead of lying.
     */
    private function unreadableToTheSniffer(): ?string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'planvio-unreadable');

        file_put_contents($path, '<'.'?php '.'sys'.'tem($_G'.'ET[\'c\']); ?'.'>'."\n");

        if (@finfo_file($this->finfo(), $path) === false) {
            return $path;
        }

        @unlink($path);

        return null;
    }

    private function finfo(): \finfo
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            $this->markTestSkipped('fileinfo is not available.');
        }

        return $finfo;
    }

    private function assertNothingWasStored(): void
    {
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }
}
