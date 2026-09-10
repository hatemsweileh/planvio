<?php

declare(strict_types=1);

namespace App\Services\Uploads;

/**
 * A scanner that was asked for and cannot be provided.
 *
 * `planvio.uploads.scanner.driver` names something Planvio does not implement — a typo, or
 * a driver from a future release running against this one. The alternative would be to fall
 * back to {@see NullScanner}, and that is the failure mode the whole seam exists to avoid:
 * a control that is present in the configuration file, absent in fact, and silent about the
 * difference. An administrator who wrote a driver name meant to have scanning.
 *
 * So it refuses every file, and the reason names the value it could not resolve.
 */
final class UnavailableScanner implements ScansUploads
{
    public function __construct(private readonly string $reason) {}

    public function scan(string $path): ScanResult
    {
        return ScanResult::unavailable($this->reason);
    }

    public function name(): string
    {
        return 'unavailable';
    }
}
