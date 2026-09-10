<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Actions\Attachments\StoreAttachment;
use App\Exceptions\UploadRejected;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Uploads\ClamAvScanner;
use App\Services\Uploads\NullScanner;
use App\Services\Uploads\ScannerFactory;
use App\Services\Uploads\ScansUploads;
use App\Services\Uploads\UnavailableScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The pluggable upload scanner (docs/SECURITY.md §6.7).
 *
 * Planvio cannot ship a virus scanner. It ships the seam, and the seam is only worth having
 * if it fails the right way, so that is most of what is asserted here:
 *
 *   - With no scanner configured, nothing changes. The extension, MIME and SVG controls are
 *     the whole gate, and the file goes in.
 *   - With ClamAV configured and answering, a `FOUND` verdict refuses the file and an `OK`
 *     verdict stores it.
 *   - **With ClamAV configured and not answering, the file is refused.** An installation
 *     that turned scanning on and then let uploads through because the daemon was restarting
 *     would have a control that exists in the config file and nowhere else.
 *   - The scan happens after the cheap checks, so a `.php` upload is still refused as a
 *     blocked extension whether or not a daemon is running.
 *
 * The ClamAV conversations run over a socket pair rather than against a daemon: the wire
 * protocol is the part of that class worth testing, and it is exactly the part a mock of
 * the scanner interface would skip.
 */
final class UploadScanningTest extends TestCase
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

    /** @var list<resource> */
    private array $sockets = [];

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
        foreach ($this->sockets as $socket) {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }

        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->sockets = [];
        $this->tempFiles = [];

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ *
     * The default: no scanner
     * ------------------------------------------------------------------ */

    #[Test]
    public function no_scanner_is_configured_by_default(): void
    {
        $this->assertInstanceOf(NullScanner::class, ScannerFactory::fromConfig());
        $this->assertInstanceOf(NullScanner::class, app(ScansUploads::class));
    }

    #[Test]
    public function an_upload_is_stored_when_nothing_is_scanning(): void
    {
        $attachment = $this->store('photo.png', $this->png());

        $this->assertInstanceOf(Attachment::class, $attachment);
        Storage::disk('private')->assertExists((string) $attachment->path);
    }

    /* ------------------------------------------------------------------ *
     * Fail closed
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_configured_scanner_that_cannot_be_reached_refuses_the_upload(): void
    {
        $this->configureClamAv(['host' => '127.0.0.1', 'port' => self::closedPort(), 'timeout' => 1]);

        try {
            $this->store('photo.png', $this->png());
            $this->fail('An unreachable scanner accepted an upload. It must fail closed.');
        } catch (UploadRejected $rejected) {
            $this->assertSame(UploadRejected::REASON_SCANNER_UNAVAILABLE, $rejected->reason);
        }

        $this->assertSame(0, Attachment::withoutWorkspaceScope()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    /**
     * The refusal has to name the scanner and the reason for the person who can fix it —
     * and must not put either in front of the person who uploaded the file.
     */
    #[Test]
    public function the_unreachable_refusal_carries_a_reason_an_administrator_can_act_on(): void
    {
        $this->configureClamAv(['host' => '127.0.0.1', 'port' => self::closedPort(), 'timeout' => 1]);

        try {
            $this->store('photo.png', $this->png());
            $this->fail('An unreachable scanner accepted an upload.');
        } catch (UploadRejected $rejected) {
            $context = $rejected->context();

            $this->assertSame('clamav', $context['scanner'] ?? null);
            $this->assertIsString($context['detail'] ?? null);
            $this->assertNotSame('', $context['detail']);

            $this->assertStringNotContainsString('clamav', $rejected->getMessage());
            $this->assertStringNotContainsString('127.0.0.1', $rejected->getMessage());
        }
    }

    #[Test]
    public function a_driver_planvio_does_not_implement_refuses_rather_than_falling_back(): void
    {
        config(['planvio.uploads.scanner.driver' => 'sophos']);

        $scanner = ScannerFactory::fromConfig();

        $this->assertInstanceOf(UnavailableScanner::class, $scanner);

        $this->expectException(UploadRejected::class);

        $this->store('photo.png', $this->png());
    }

    /* ------------------------------------------------------------------ *
     * ClamAV, over the wire
     * ------------------------------------------------------------------ */

    #[Test]
    public function a_clean_verdict_lets_the_file_through(): void
    {
        $this->bindClamAvAnswering("stream: OK\0");

        $attachment = $this->store('photo.png', $this->png());

        Storage::disk('private')->assertExists((string) $attachment->path);
    }

    #[Test]
    public function a_found_verdict_refuses_the_file(): void
    {
        $this->bindClamAvAnswering("stream: Eicar-Test-Signature FOUND\0");

        try {
            $this->store('photo.png', $this->png());
            $this->fail('A file the scanner recognised was stored.');
        } catch (UploadRejected $rejected) {
            $this->assertSame(UploadRejected::REASON_INFECTED, $rejected->reason);
            $this->assertSame('Eicar-Test-Signature', $rejected->context()['signature'] ?? null);

            // The signature is a fact for the audit trail, not a sentence for the uploader.
            $this->assertStringNotContainsString('Eicar', $rejected->getMessage());
        }

        $this->assertSame(0, Attachment::withoutWorkspaceScope()->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    #[Test]
    public function an_error_from_the_daemon_is_treated_as_no_answer_at_all(): void
    {
        $this->bindClamAvAnswering("INSTREAM size limit exceeded. ERROR\0");

        try {
            $this->store('photo.png', $this->png());
            $this->fail('A daemon error was read as a pass.');
        } catch (UploadRejected $rejected) {
            $this->assertSame(UploadRejected::REASON_SCANNER_UNAVAILABLE, $rejected->reason);
        }
    }

    #[Test]
    public function a_daemon_that_says_nothing_is_not_a_pass(): void
    {
        $this->bindClamAvAnswering('');

        try {
            $this->store('photo.png', $this->png());
            $this->fail('Silence was read as a pass.');
        } catch (UploadRejected $rejected) {
            $this->assertSame(UploadRejected::REASON_SCANNER_UNAVAILABLE, $rejected->reason);
        }
    }

    /**
     * The INSTREAM framing, read back off the wire: the command, then a length-prefixed
     * chunk, then the four zero bytes that end the stream.
     */
    #[Test]
    public function the_file_is_sent_using_clamav_instream_framing(): void
    {
        [$scanner, $peer] = $this->clamAvOver("stream: OK\0");

        $path = $this->tempFile('scan me');

        $this->assertTrue($scanner->scan($path)->passed());

        $sent = (string) stream_get_contents($peer, 4096);

        $this->assertStringStartsWith("zINSTREAM\0", $sent);

        $body = substr($sent, strlen("zINSTREAM\0"));

        $this->assertSame(pack('N', 7).'scan me'.pack('N', 0), $body);
    }

    #[Test]
    public function a_file_larger_than_the_daemon_accepts_is_refused_rather_than_truncated(): void
    {
        [$scanner] = $this->clamAvOver("stream: OK\0", ['max_bytes' => 4]);

        $result = $scanner->scan($this->tempFile('rather more than four bytes'));

        $this->assertFalse($result->available);
        $this->assertFalse($result->passed());
    }

    /* ------------------------------------------------------------------ *
     * Ordering
     * ------------------------------------------------------------------ */

    /**
     * The scan is the last control and the most expensive one. A blocked extension must be
     * refused as a blocked extension — before a daemon is asked anything, and whether or not
     * one is running.
     */
    #[Test]
    public function the_cheap_checks_run_before_the_scanner_is_asked(): void
    {
        $this->configureClamAv(['host' => '127.0.0.1', 'port' => self::closedPort(), 'timeout' => 1]);

        try {
            $this->store('shell.php', "<?php echo 1;\n");
            $this->fail('A PHP upload was accepted.');
        } catch (UploadRejected $rejected) {
            $this->assertSame(UploadRejected::REASON_BLOCKED_EXTENSION, $rejected->reason);
        }
    }

    #[Test]
    public function a_mismatched_type_is_refused_before_the_scanner_is_asked(): void
    {
        $this->configureClamAv(['host' => '127.0.0.1', 'port' => self::closedPort(), 'timeout' => 1]);

        try {
            $this->store('photo.png', 'this is plain text pretending to be a png');
            $this->fail('A file whose contents disagree with its name was accepted.');
        } catch (UploadRejected $rejected) {
            $this->assertSame(UploadRejected::REASON_MIME_MISMATCH, $rejected->reason);
        }
    }

    /* ------------------------------------------------------------------ *
     * Configuration
     * ------------------------------------------------------------------ */

    #[Test]
    public function the_clamav_driver_is_resolved_from_configuration(): void
    {
        $this->configureClamAv(['socket' => '/var/run/clamav/clamd.ctl']);

        $this->assertInstanceOf(ClamAvScanner::class, ScannerFactory::fromConfig());
        $this->assertSame('clamav', ScannerFactory::fromConfig()->name());
    }

    #[Test]
    public function an_empty_driver_is_the_null_scanner(): void
    {
        foreach ([null, '', 'null', 'none', ' CLAMAV '] as $driver) {
            config(['planvio.uploads.scanner.driver' => $driver]);

            $expected = trim((string) $driver) === '' || in_array($driver, ['null', 'none'], true)
                ? NullScanner::class
                : ClamAvScanner::class;

            $this->assertInstanceOf($expected, ScannerFactory::fromConfig());
        }
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $overrides
     */
    private function configureClamAv(array $overrides = []): void
    {
        config([
            'planvio.uploads.scanner' => $overrides + [
                'driver' => 'clamav',
                'host' => '127.0.0.1',
                'port' => 3310,
                'socket' => null,
                'timeout' => 5,
                'max_bytes' => 26214400,
            ],
        ]);
    }

    /**
     * Put a ClamAV scanner in the container whose daemon has already replied.
     *
     * The answer is written into the far end of the socket pair before the scan runs, so it
     * is waiting in the buffer when the scanner reads. A real daemon replies after reading
     * the stream; for a file this small the ordering on the wire is the same either way.
     */
    private function bindClamAvAnswering(string $response): void
    {
        [$scanner] = $this->clamAvOver($response);

        $this->app->instance(ScansUploads::class, $scanner);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{0: ClamAvScanner, 1: resource}
     */
    private function clamAvOver(string $response, array $options = []): array
    {
        $pair = stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->assertIsArray($pair, 'This platform cannot create a socket pair.');

        [$near, $far] = $pair;

        $this->sockets[] = $near;
        $this->sockets[] = $far;

        if ($response !== '') {
            fwrite($far, $response);
        } else {
            // A daemon that takes the file and then dies. Half-closing the far end lets the
            // scanner's writes succeed and its read find end-of-stream, which is what that
            // looks like on the wire — without a real timeout the suite would have to wait
            // out.
            stream_socket_shutdown($far, STREAM_SHUT_WR);
        }

        $scanner = new ClamAvScanner(
            host: (string) ($options['host'] ?? '127.0.0.1'),
            port: (int) ($options['port'] ?? 3310),
            socket: null,
            timeout: (int) ($options['timeout'] ?? 5),
            maxBytes: (int) ($options['max_bytes'] ?? 26214400),
            connector: static fn (): mixed => $near,
        );

        return [$scanner, $far];
    }

    /**
     * A TCP port nothing is listening on: bound to claim one the operating system says is
     * free, then released.
     */
    private static function closedPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if (! is_resource($server)) {
            return 9;
        }

        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        fclose($server);

        return $port;
    }

    private function store(string $name, string $contents): Attachment
    {
        return app(StoreAttachment::class)($this->task, $this->upload($name, $contents), $this->uploader);
    }

    private function upload(string $name, string $contents): UploadedFile
    {
        return new UploadedFile($this->tempFile($contents), $name, null, null, true);
    }

    private function tempFile(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'planvio-scan');
        file_put_contents($path, $contents);

        $this->tempFiles[] = $path;

        return $path;
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG_BASE64, true);
    }
}
