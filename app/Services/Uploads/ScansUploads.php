<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Actions\Attachments\StoreAttachment;

/**
 * A malware check over an uploaded file, before it is stored.
 *
 * Planvio cannot ship a scanner. Signatures need updating daily, a scanning daemon needs
 * memory and a process, and neither belongs in a ZIP an administrator extracts into a
 * cPanel account. What Planvio can ship — and does, here — is the seam, so that an
 * installation which already runs ClamAV can put it in the upload path with a config value
 * rather than a fork.
 *
 * Implementations are resolved from `planvio.uploads.scanner.driver` and called by
 * {@see StoreAttachment} *after* the extension, size and MIME
 * controls have passed. That order is deliberate: those checks are free and reject the
 * overwhelming majority of bad uploads, and there is no reason to hand a file the gate has
 * already refused to a daemon that will charge CPU for the same answer.
 *
 * ## The contract
 *
 * `scan()` is given an absolute path to a readable file on the local filesystem — the
 * temporary upload, before anything has been written to a disk. It must not move, modify
 * or delete it.
 *
 * It must not throw. Every failure mode an implementation can have — refused connection,
 * timeout, protocol garbage, a file it cannot read — is a {@see ScanResult::unavailable()},
 * because the caller has to be able to tell "this scanner passed the file" from "this
 * scanner did not answer", and an exception collapses that distinction into a 500.
 */
interface ScansUploads
{
    /**
     * @param string $path absolute path to the file to examine
     */
    public function scan(string $path): ScanResult;

    /**
     * A short name for logs and for the refusal an administrator reads — `null`, `clamav`.
     */
    public function name(): string;
}
