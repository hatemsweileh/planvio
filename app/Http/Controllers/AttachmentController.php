<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only way to read an attachment's bytes (ARCHITECTURE.md §9).
 *
 * Three properties matter here, and each of them is a control rather than a convenience:
 *
 *  - **It authorises.** `Gate::authorize('view', $attachment)` runs the AttachmentPolicy for
 *    the acting user, which resolves their workspace membership independently of the
 *    workspace scope. A non-member never gets bytes.
 *  - **It streams, it never redirects.** A temporary URL or a `public/storage` path would
 *    hand out a link that outlives the check above and travels in referrers, proxies and
 *    chat logs. The response is always the file itself, read off the private disk.
 *  - **It refuses to let stored bytes execute in this origin.** Only a short allow-list of
 *    inert types is served inline; everything else — an uploaded `.html`, an `.svg` carrying
 *    script, an office document with a macro — is forced to download, its `Content-Type`
 *    flattened to `application/octet-stream`, with `nosniff` so no browser second-guesses
 *    it. An attachment that renders as a page inside `planvio.example.com` is a stored XSS
 *    with a session attached.
 *
 * The stored path is never emitted, in a header or a body: it is a filesystem detail, and
 * the only name the user has any business seeing is the one they uploaded.
 */
final class AttachmentController extends Controller
{
    /**
     * Media types safe to render in the browser without handing the page's origin to the
     * uploader. SVG is deliberately absent: it is a document that can carry script.
     *
     * @var list<string>
     */
    private const INLINE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/bmp',
        'image/tiff',
        'application/pdf',
    ];

    public function __invoke(Request $request, Attachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment);

        // The disk is read off the record rather than assumed, but it is only ever honoured
        // when it names a disk this installation actually configured — a row pointing at
        // `public` or at nothing must not silently widen where bytes may come from.
        $disk = is_string($attachment->disk) && $attachment->disk !== ''
            ? $attachment->disk
            : 'private';

        if (! is_array(config("filesystems.disks.{$disk}"))) {
            $disk = 'private';
        }

        $filesystem = Storage::disk($disk);
        $path = (string) $attachment->path;

        abort_if($path === '' || ! $filesystem->exists($path), 404);

        $inline = in_array($attachment->mime, self::INLINE_TYPES, true)
            && ! $request->boolean('download');

        $filename = $this->filename($attachment);

        return $filesystem->response($path, $filename, [
            // The length comes from the disk, not from `size_bytes`: the column is a cached
            // fact about the upload and a stale one would truncate the download.
            'Content-Type' => $inline ? (string) $attachment->mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            // Belt and braces for the inline branch: even a type that slipped onto the
            // allow-list could not reach the network or run a script from here.
            'Content-Security-Policy' => "default-src 'none'; img-src 'self' data:; object-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ], $inline ? 'inline' : 'attachment');
    }

    /**
     * The name the browser saves the file under: the uploader's own, stripped of anything
     * that could change where it lands or how it is parsed.
     */
    private function filename(Attachment $attachment): string
    {
        // Directories are dropped first, so `../../etc/passwd` becomes `passwd` rather than
        // the run-together `..etcpasswd` that stripping separators alone would leave.
        $name = str_replace('\\', '/', (string) $attachment->original_name);
        $name = basename($name);

        // Null bytes, control characters and quotes are removed rather than escaped: none of
        // them belong in a filename, and a header containing them is a header the client is
        // free to interpret creatively.
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);
        $name = ltrim(trim($name), '.');

        if ($name === '') {
            $extension = (string) $attachment->extension;

            return 'attachment-'.$attachment->getKey().($extension === '' ? '' : '.'.$extension);
        }

        return mb_substr($name, 0, 200);
    }
}
