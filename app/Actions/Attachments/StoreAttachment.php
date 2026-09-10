<?php

declare(strict_types=1);

namespace App\Actions\Attachments;

use App\Events\Attachments\AttachmentStored;
use App\Exceptions\UploadRejected;
use App\Models\Attachment;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Uploads\NullScanner;
use App\Services\Uploads\ScansUploads;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The upload gate. Every byte a user puts into Planvio comes through here.
 *
 * The controls, in the order they run, and why each one exists:
 *
 * 1. **Blocked extensions are refused unconditionally**, including in the middle of a name.
 *    `report.php.png` is not a PNG with an odd name; it is a file that Apache will happily
 *    hand to the PHP handler on a server with a sloppy `AddHandler` line. This check runs
 *    before the allow-lists and cannot be reached around by configuration.
 * 2. **The extension must be allowed AND the sniffed type must be allowed** — both, never
 *    either. An allow-listed extension proves nothing about content, and an allow-listed
 *    type proves nothing about how the web server will treat the name.
 * 3. **The sniffed type must match what the extension claims.** Without this pairing, a
 *    file called `avatar.png` whose contents sniff as `text/plain` passes both allow-lists
 *    while being something else entirely.
 * 4. **The client filename is never used on disk.** The stored name is random, so no
 *    traversal sequence, null byte, reserved Windows name or overlong path in the uploaded
 *    name can influence where the bytes land. The original is kept as data, for display.
 * 5. **The configured scanner sees the bytes**, last, because it is the only check that
 *    costs anything. Everything above is arithmetic on a filename and a few bytes of
 *    header; handing a daemon a file the gate has already refused would buy the same
 *    answer at CPU price. {@see ScansUploads} is a {@see NullScanner} unless an
 *    administrator configured one — and a scanner that *is* configured and cannot be
 *    reached refuses the upload rather than waving it through.
 * 6. **SVGs are rewritten, not trusted**, because an SVG is a document that can carry
 *    script (see {@see SvgSanitizer}).
 *
 * Files land on the `private` disk under a workspace-scoped path and are only ever served
 * by the authorising download route (ARCHITECTURE.md §9).
 */
final class StoreAttachment
{
    /**
     * What each allowed extension is permitted to sniff as.
     *
     * Deliberately narrow for anything a browser might execute or render, and forgiving
     * where libmagic genuinely cannot tell formats apart: every OOXML and OpenDocument
     * file is a zip, and every text format looks like `text/plain` to a scanner that only
     * sees bytes. An extension missing from this table falls back to the configured MIME
     * allow-list alone, so an administrator adding a format to `config/planvio.php` does
     * not have to edit code.
     *
     * @var array<string, list<string>>
     */
    private const MIME_BY_EXTENSION = [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'bmp' => ['image/bmp'],
        'tiff' => ['image/tiff'],
        'heic' => ['image/heic'],
        // Sanitised and re-parsed as XML before storage, so a text/* verdict is safe here.
        'svg' => ['image/svg+xml', 'application/xml', 'text/xml', 'text/plain'],

        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'odt' => ['application/vnd.oasis.opendocument.text', 'application/zip'],
        'rtf' => ['application/rtf', 'text/plain'],
        'txt' => ['text/plain'],
        'md' => ['text/markdown', 'text/plain'],

        'xls' => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ods' => ['application/vnd.oasis.opendocument.spreadsheet', 'application/zip'],
        'csv' => ['text/csv', 'text/plain'],
        'tsv' => ['text/tab-separated-values', 'text/plain'],

        'ppt' => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'odp' => ['application/vnd.oasis.opendocument.presentation', 'application/zip'],

        'zip' => ['application/zip'],
        'gz' => ['application/gzip'],
        'tar' => ['application/x-tar'],
        'rar' => ['application/vnd.rar'],
        '7z' => ['application/x-7z-compressed'],

        'mp3' => ['audio/mpeg'],
        'wav' => ['audio/wav'],
        'ogg' => ['audio/ogg'],
        'm4a' => ['audio/mp4', 'video/mp4'],
        'mp4' => ['video/mp4'],
        'webm' => ['video/webm'],
        'mov' => ['video/quicktime'],
        'avi' => ['video/x-msvideo'],
        'mkv' => ['video/x-matroska'],

        'json' => ['application/json', 'text/plain'],
        'xml' => ['application/xml', 'text/xml', 'text/plain'],
        'ics' => ['text/calendar', 'text/plain'],
    ];

    private const MAX_ORIGINAL_NAME_CHARS = 255;

    public function __construct(
        private readonly SvgSanitizer $svg,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
        private readonly FilesystemFactory $filesystem,
        private readonly ScansUploads $scanner,
    ) {}

    public function __invoke(Model $attachable, UploadedFile $file, User $uploader): Attachment
    {
        $workspaceId = self::workspaceIdOf($attachable);
        $originalName = self::safeOriginalName($file->getClientOriginalName());

        if (! $file->isValid()) {
            throw UploadRejected::uploadFailed($originalName);
        }

        $sourcePath = $file->getRealPath();

        if ($sourcePath === false || ! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw UploadRejected::unreadable($originalName);
        }

        $extension = $this->guardExtension($originalName);
        $size = self::sizeOf($sourcePath, $originalName);
        $this->guardSize($size, $originalName);

        $mime = $this->guardMime($sourcePath, $extension, $originalName);

        $this->guardContents($sourcePath, $originalName);

        [$contents, $size] = $this->prepareContents($sourcePath, $extension, $mime, $size, $originalName);

        $checksum = self::checksum($sourcePath, $contents, $originalName);

        $duplicate = $this->existingCopy($attachable, $workspaceId, $checksum, $size);

        if ($duplicate instanceof Attachment) {
            return $duplicate;
        }

        $disk = (string) config('planvio.uploads.disk', 'private');
        $path = $this->write($disk, $workspaceId, $extension, $file, $contents, $originalName);

        try {
            $attachment = DB::transaction(function () use (
                $attachable,
                $workspaceId,
                $uploader,
                $disk,
                $path,
                $originalName,
                $mime,
                $extension,
                $size,
                $checksum,
            ): Attachment {
                $attachment = Attachment::query()->create([
                    'workspace_id' => $workspaceId,
                    'attachable_id' => $attachable->getKey(),
                    'attachable_type' => $attachable->getMorphClass(),
                    'uploaded_by' => $uploader->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $originalName,
                    'mime' => $mime,
                    'extension' => $extension,
                    'size_bytes' => $size,
                    'checksum' => $checksum,
                ]);

                $this->activity->forUser($uploader)->log($attachable, 'attachment_added', [
                    'attachment_id' => (int) $attachment->getKey(),
                    'original_name' => $originalName,
                    'mime' => $mime,
                    'size_bytes' => $size,
                ]);

                return $attachment;
            });
        } catch (Throwable $failure) {
            // The bytes are already on disk and the row that would have owned them was
            // rolled back; without this the file is unreachable and unreclaimable.
            $this->filesystem->disk($disk)->delete($path);

            throw $failure;
        }

        $this->events->dispatch(new AttachmentStored($attachment, $attachable, $uploader));

        return $attachment;
    }

    /* ------------------------------------------------------------------ *
     * Controls
     * ------------------------------------------------------------------ */

    /**
     * The client filename, reduced to something that is safe to store and display.
     *
     * It is never used to build a path, so this is defence in depth rather than the
     * traversal control — but a name is echoed back into headers and pages, and one
     * carrying a null byte, a newline or a directory separator has no business there.
     */
    private static function safeOriginalName(string $clientName): string
    {
        $name = str_replace('\\', '/', $clientName);

        $separator = strrpos($name, '/');
        $name = $separator === false ? $name : substr($name, $separator + 1);

        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name);
        // Trailing dots and spaces are stripped by Windows when a file is created, which
        // is how "evil.php " becomes "evil.php" after every check has passed.
        $name = rtrim($name, " \t.");

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'upload';
        }

        return mb_substr($name, 0, self::MAX_ORIGINAL_NAME_CHARS);
    }

    /**
     * @return string the lower-cased, allow-listed extension
     */
    private function guardExtension(string $originalName): string
    {
        $segments = explode('.', mb_strtolower($originalName, 'UTF-8'));
        $extension = array_key_last($segments) === 0 ? '' : (string) end($segments);

        /** @var list<string> $blocked */
        $blocked = (array) config('planvio.uploads.blocked_extensions', []);

        // Every segment, not just the last: `report.php.png` is a PHP file to any server
        // configured to route on an interior extension.
        foreach (array_slice($segments, 1) as $segment) {
            if ($segment !== '' && in_array($segment, $blocked, true)) {
                throw UploadRejected::blockedExtension($segment, $originalName);
            }
        }

        if ($extension === '') {
            throw UploadRejected::withoutExtension($originalName);
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('planvio.uploads.allowed_extensions', []);

        if (! in_array($extension, $allowed, true)) {
            throw UploadRejected::extensionNotAllowed($extension, $originalName);
        }

        return $extension;
    }

    private function guardSize(int $size, string $originalName): void
    {
        if ($size <= 0) {
            throw UploadRejected::emptyFile($originalName);
        }

        $limit = max(0, (int) config('planvio.uploads.max_size_kb', 20480)) * 1024;

        if ($limit > 0 && $size > $limit) {
            throw UploadRejected::tooLarge($size, $limit, $originalName);
        }
    }

    /**
     * Sniff the file's real type and require it to agree with both the allow-list and the
     * extension. `finfo` reads the bytes; the browser-supplied `Content-Type` is ignored
     * entirely, because it is written by whoever is uploading.
     */
    private function guardMime(string $sourcePath, string $extension, string $originalName): string
    {
        $mime = self::sniff($sourcePath);

        if ($mime === null) {
            throw UploadRejected::unreadable($originalName);
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('planvio.uploads.allowed_mimes', []);

        if (! in_array($mime, $allowed, true)) {
            throw UploadRejected::mimeNotAllowed($mime, $originalName);
        }

        $expected = self::MIME_BY_EXTENSION[$extension] ?? null;

        if ($expected !== null && ! in_array($mime, $expected, true)) {
            throw UploadRejected::mimeMismatch($mime, $extension, $originalName);
        }

        return $mime;
    }

    /**
     * Diagnostics are suppressed on both calls, and the return values are what decide.
     *
     * Opening the file can fail for reasons this code does not control and cannot prevent:
     * an endpoint scanner holding a handle on content it recognises, the temporary file
     * swept between the readability check and here, a permission or filesystem error. PHP
     * signals all of them by emitting a warning *and* returning false — and Laravel's error
     * handler turns any warning into an `ErrorException`. Left unsuppressed, that exception
     * unwinds past the whole gate as a 500, the `null` returns below become unreachable,
     * and the refusal loses the `reason` the security trail counts.
     */
    private static function sniff(string $path): ?string
    {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        try {
            $mime = @finfo_file($finfo, $path);
        } finally {
            finfo_close($finfo);
        }

        if (! is_string($mime) || $mime === '') {
            return null;
        }

        // libmagic sometimes returns "type; charset=…" despite FILEINFO_MIME_TYPE.
        $mime = strtolower(trim((string) strtok($mime, ';')));

        return $mime === '' ? null : $mime;
    }

    /**
     * Hand the bytes to whatever scanner this installation configured.
     *
     * Scanned before the SVG rewrite, deliberately: the file to examine is the one that
     * arrived, not the one Planvio produced from it. A payload hidden in a comment the
     * sanitiser would have dropped is still a payload somebody uploaded, and an
     * administrator watching `blocked` counts wants to know it was attempted.
     *
     * Both refusals are the same to the uploader — their file did not go in — and entirely
     * different to whoever reads the audit trail, which is why they are separate reasons.
     */
    private function guardContents(string $sourcePath, string $originalName): void
    {
        $result = $this->scanner->scan($sourcePath);

        if ($result->passed()) {
            return;
        }

        if (! $result->available) {
            throw UploadRejected::scannerUnavailable(
                $this->scanner->name(),
                $result->detail ?? 'no reason given',
                $originalName,
            );
        }

        throw UploadRejected::infected($result->signature ?? 'unnamed signature', $originalName);
    }

    /**
     * SVGs are stored rewritten; everything else is streamed from the temporary file
     * untouched, so a 2 GB video never has to fit in memory.
     *
     * @return array{0: string|null, 1: int} the bytes to write (null = stream), and the size
     */
    private function prepareContents(
        string $sourcePath,
        string $extension,
        string $mime,
        int $size,
        string $originalName,
    ): array {
        $isSvg = $extension === 'svg' || $mime === 'image/svg+xml';

        if (! $isSvg || config('planvio.uploads.sanitise_svg', true) !== true) {
            return [null, $size];
        }

        $raw = @file_get_contents($sourcePath);

        if ($raw === false) {
            throw UploadRejected::unreadable($originalName);
        }

        $clean = $this->svg->sanitize($raw);

        if ($clean === null) {
            // It claimed to be an SVG and is not one. Refusing beats storing an unknown
            // document under a name browsers will render.
            throw UploadRejected::mimeMismatch($mime, $extension, $originalName);
        }

        return [$clean, strlen($clean)];
    }

    /**
     * An identical file already on the same record is the same attachment: a retried
     * upload, a double-clicked button, an agent repeating a tool call.
     */
    private function existingCopy(Model $attachable, int $workspaceId, string $checksum, int $size): ?Attachment
    {
        return Attachment::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->where('attachable_type', $attachable->getMorphClass())
            ->where('attachable_id', $attachable->getKey())
            ->where('checksum', $checksum)
            ->where('size_bytes', $size)
            ->first();
    }

    /**
     * Write the bytes under a random name inside the workspace's own directory.
     *
     * @return string the path relative to the disk root
     */
    private function write(
        string $disk,
        int $workspaceId,
        string $extension,
        UploadedFile $file,
        ?string $contents,
        string $originalName,
    ): string {
        $now = Carbon::now();

        $directory = sprintf(
            'attachments/%d/%s/%s',
            $workspaceId,
            $now->format('Y'),
            $now->format('m'),
        );

        // 40 random characters: the name carries no information from the upload at all, so
        // it cannot be guessed from the original filename and cannot contain a path.
        $filename = Str::random(40).'.'.$extension;
        $path = $directory.'/'.$filename;

        $storage = $this->filesystem->disk($disk);

        $written = $contents === null
            ? $storage->putFileAs($directory, $file, $filename)
            : $storage->put($path, $contents);

        if ($written === false) {
            throw UploadRejected::storeFailed($originalName);
        }

        return is_string($written) ? $written : $path;
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    private static function workspaceIdOf(Model $attachable): int
    {
        $workspaceId = $attachable->getAttribute('workspace_id');

        if ($workspaceId === null) {
            throw UploadRejected::notAttachable($attachable->getMorphClass());
        }

        return (int) $workspaceId;
    }

    /**
     * The content hash — of the rewritten bytes when there are any, of the temporary file
     * otherwise.
     *
     * Reading the file can fail for the same reasons sniffing it can, so the diagnostic is
     * suppressed and the `false` is handled. Casting it away would store an empty checksum
     * as a fact about the file and quietly break the duplicate check, which compares on it.
     */
    private static function checksum(string $sourcePath, ?string $contents, string $originalName): string
    {
        if ($contents !== null) {
            return hash('sha256', $contents);
        }

        $checksum = @hash_file('sha256', $sourcePath);

        if (! is_string($checksum) || $checksum === '') {
            throw UploadRejected::unreadable($originalName);
        }

        return $checksum;
    }

    private static function sizeOf(string $sourcePath, string $originalName): int
    {
        $size = @filesize($sourcePath);

        if ($size === false) {
            throw UploadRejected::unreadable($originalName);
        }

        return (int) $size;
    }
}
