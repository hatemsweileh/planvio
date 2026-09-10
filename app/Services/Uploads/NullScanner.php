<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Actions\Attachments\StoreAttachment;

/**
 * The default: no content scanning.
 *
 * This is not a stub standing in for something unfinished. It is what an installation with
 * no ClamAV honestly has, expressed as an object rather than as a branch, so that
 * {@see StoreAttachment} has exactly one path through the gate
 * whether or not a scanner is configured.
 *
 * Everything else in the gate still runs. A file reaching here has already had its
 * extension checked against the blocked list and the allow-list, its bytes sniffed and
 * matched against what the extension claims, and — if it is an SVG — been parsed and
 * rewritten. What is missing is the one thing signatures buy: recognising a known-bad
 * payload inside a file whose *shape* is entirely legitimate. A real Word document
 * carrying a real macro passes every check Planvio can make on its own.
 */
final class NullScanner implements ScansUploads
{
    public function scan(string $path): ScanResult
    {
        return ScanResult::clean();
    }

    public function name(): string
    {
        return 'null';
    }
}
