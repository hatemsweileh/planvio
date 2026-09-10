<?php

declare(strict_types=1);

namespace App\Services\Uploads;

use App\Actions\Attachments\StoreAttachment;
use App\Providers\AppServiceProvider;

/**
 * Turns `planvio.uploads.scanner` into the object the upload gate calls.
 *
 * Bound in {@see AppServiceProvider} rather than resolved at the call site,
 * so {@see StoreAttachment} takes a {@see ScansUploads} and has no
 * idea which one it got — the difference between "this installation has ClamAV" and "this
 * one does not" is a container binding, not a branch inside the gate.
 *
 * It is a fresh instance per resolution rather than a singleton. A scanner holds no state
 * between files, and an administrator changing the driver should get the new one on the
 * next upload rather than on the next deploy.
 */
final class ScannerFactory
{
    public const DRIVER_NONE = 'null';

    public const DRIVER_CLAMAV = 'clamav';

    public static function fromConfig(): ScansUploads
    {
        /** @var array<string, mixed> $settings */
        $settings = (array) config('planvio.uploads.scanner', []);

        $driver = $settings['driver'] ?? null;
        $driver = is_string($driver) ? mb_strtolower(trim($driver)) : '';

        return match ($driver) {
            '', self::DRIVER_NONE, 'none', 'false', '0' => new NullScanner,
            self::DRIVER_CLAMAV => self::clamav($settings),
            default => new UnavailableScanner(
                sprintf('upload scanning is set to "%s", which is not a driver Planvio provides', $driver),
            ),
        };
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function clamav(array $settings): ScansUploads
    {
        $socket = $settings['socket'] ?? null;
        $socket = is_string($socket) && trim($socket) !== '' ? trim($socket) : null;

        $host = $settings['host'] ?? '127.0.0.1';
        $host = is_string($host) && trim($host) !== '' ? trim($host) : '127.0.0.1';

        return new ClamAvScanner(
            host: $host,
            port: self::positive($settings['port'] ?? null, 3310),
            socket: $socket,
            timeout: self::positive($settings['timeout'] ?? null, 30),
            maxBytes: self::positive($settings['max_bytes'] ?? null, 26214400),
        );
    }

    private static function positive(mixed $value, int $fallback): int
    {
        $number = is_numeric($value) ? (int) $value : 0;

        return $number > 0 ? $number : $fallback;
    }
}
