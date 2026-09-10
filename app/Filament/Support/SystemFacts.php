<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\AiProvider;
use App\Support\Settings;
use App\Support\Version;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The facts about this installation, gathered once and read by System Information, System
 * Health and the dashboard widgets.
 *
 * Everything here is defensive on purpose. This is the screen somebody opens *because*
 * something is wrong: a database that will not answer, a storage path that is not writable, a
 * `settings` table that does not exist yet. A fact-gathering method that throws turns the one
 * diagnostic page into a second failure, so every probe catches and reports the absence.
 *
 * No credential is read here, and none is reported. The AI section says whether a key exists,
 * never what it is.
 */
final class SystemFacts
{
    private function __construct() {}

    /* ------------------------------------------------------------------ *
     * Application
     * ------------------------------------------------------------------ */

    public static function appVersion(): string
    {
        return Version::app();
    }

    /**
     * The schema generation the code expects, and the one the database says it is at.
     *
     * A mismatch means migrations are outstanding — which is exactly the thing that looks like
     * a working installation until something reads a column that is not there.
     *
     * @return array{expected: string, installed: ?string, matches: bool}
     */
    public static function databaseVersion(): array
    {
        $expected = Version::db();
        $installed = self::setting('db_version');
        $installed = is_string($installed) && $installed !== '' ? $installed : null;

        return [
            'expected' => $expected,
            'installed' => $installed,
            'matches' => $installed === null || $installed === $expected,
        ];
    }

    public static function laravelVersion(): string
    {
        return Version::laravel();
    }

    public static function phpVersion(): string
    {
        return Version::php();
    }

    /**
     * The PHP binary this request is running under.
     *
     * `PHP_BINARY` is the whole reason the cron section of this page is worth having: on cPanel
     * and CloudLinux the account default (`/usr/local/bin/php`) is routinely a different, older
     * version from the one the site is served with, and a cron entry using it fails silently.
     * This is the path that is definitely correct, because it is the one executing right now.
     */
    public static function phpBinary(): string
    {
        $binary = PHP_BINARY;

        return $binary === '' ? 'php' : $binary;
    }

    public static function artisanPath(): string
    {
        return base_path('artisan');
    }

    /**
     * The two cron entries from docs/CRON.md, with this installation's real paths filled in.
     *
     * @return array<string, array{schedule: string, command: string, description: string}>
     */
    public static function cronLines(): array
    {
        $php = self::phpBinary();
        $artisan = self::artisanPath();

        return [
            'scheduler' => [
                'schedule' => '* * * * *',
                'command' => $php.' '.$artisan.' schedule:run >/dev/null 2>&1',
                'description' => __('Reminders, recurring tasks, AI automations and nightly maintenance. Without this, none of them fire.'),
            ],
            'queue' => [
                'schedule' => '*/5 * * * *',
                'command' => $php.' '.$artisan.' queue:work database --queue=default,'
                    .(string) config('ai.queue.name', 'ai')
                    .' --stop-when-empty --max-time=280 --tries=3 >/dev/null 2>&1',
                'description' => __('Sends queued email, delivers webhooks and runs queued AI work. --stop-when-empty is what makes it safe on shared hosting: it exits when the queue is empty instead of running as a daemon.'),
            ],
        ];
    }

    /* ------------------------------------------------------------------ *
     * Environment
     * ------------------------------------------------------------------ */

    /**
     * @return array{driver: string, version: string, database: string, host: ?string}
     */
    public static function database(): array
    {
        try {
            $connection = DB::connection();
            $config = $connection->getConfig();

            return [
                'driver' => self::driverLabel((string) $connection->getDriverName()),
                'version' => $connection->getServerVersion(),
                'database' => self::databaseName($config),
                'host' => is_string($config['host'] ?? null) ? $config['host'] : null,
            ];
        } catch (Throwable) {
            return [
                'driver' => (string) config('database.default'),
                'version' => __('Unavailable'),
                'database' => __('Unavailable'),
                'host' => null,
            ];
        }
    }

    public static function serverSoftware(): string
    {
        $software = $_SERVER['SERVER_SOFTWARE'] ?? null;

        return is_string($software) && $software !== '' ? $software : __('Not reported (console or built-in server)');
    }

    public static function operatingSystem(): string
    {
        return PHP_OS_FAMILY.' · '.php_uname('r');
    }

    public static function memoryLimit(): string
    {
        return self::iniValue('memory_limit');
    }

    /**
     * What can actually be uploaded, which is the smaller of two PHP limits and Planvio's own.
     *
     * People raise `upload_max_filesize`, forget `post_max_size`, and then cannot work out why
     * a 30 MB file still fails. Reporting all three side by side is the answer.
     *
     * @return array{upload_max_filesize: string, post_max_size: string, planvio_limit: string, effective: string}
     */
    public static function uploadLimits(): array
    {
        $upload = self::bytesFromIni('upload_max_filesize');
        $post = self::bytesFromIni('post_max_size');
        $planvio = ((int) config('planvio.uploads.max_size_kb', 20480)) * 1024;

        $candidates = array_values(array_filter([$upload, $post, $planvio], static fn (?int $v): bool => $v !== null && $v > 0));

        return [
            'upload_max_filesize' => self::iniValue('upload_max_filesize'),
            'post_max_size' => self::iniValue('post_max_size'),
            'planvio_limit' => self::humanBytes($planvio),
            'effective' => $candidates === [] ? __('Unknown') : self::humanBytes(min($candidates)),
        ];
    }

    public static function maxExecutionTime(): string
    {
        $value = self::iniValue('max_execution_time');

        return $value === '0' ? __('Unlimited') : $value.' '.__('seconds');
    }

    /* ------------------------------------------------------------------ *
     * Storage, queue, scheduler
     * ------------------------------------------------------------------ */

    /**
     * @return array{default: string, private_driver: string, private_root: ?string, public_link: bool}
     */
    public static function storage(): array
    {
        $default = (string) config('filesystems.default', 'local');
        $privateDriver = (string) config('filesystems.disks.'.config('planvio.uploads.disk', 'private').'.driver', 'local');
        $privateRoot = config('filesystems.disks.'.config('planvio.uploads.disk', 'private').'.root');

        return [
            'default' => $default,
            'private_driver' => $privateDriver,
            'private_root' => is_string($privateRoot) ? $privateRoot : null,
            'public_link' => is_link(public_path('storage')) || is_dir(public_path('storage')),
        ];
    }

    /**
     * @return array{connection: string, driver: string, pending: ?int, failed: ?int}
     */
    public static function queue(): array
    {
        $connection = (string) config('queue.default', 'database');
        $driver = (string) config('queue.connections.'.$connection.'.driver', $connection);

        return [
            'connection' => $connection,
            'driver' => $driver,
            'pending' => self::count('jobs'),
            'failed' => self::count('failed_jobs'),
        ];
    }

    /**
     * When `planvio:heartbeat` last wrote, and nothing more.
     *
     * This is the only proof that cron is alive that works on hosting with no shell and no
     * readable log. A null here means the scheduler has *never* run — not that it ran and
     * found nothing to do.
     */
    public static function schedulerLastRun(): ?Carbon
    {
        $value = self::setting('system.scheduler.last_run_at');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ *
     * AI
     * ------------------------------------------------------------------ */

    /**
     * @return array{enabled: bool, provider: ?string, driver: ?string, model: ?string, has_key: bool, base_url: ?string, configured: bool}
     */
    public static function ai(): array
    {
        $enabled = (bool) config('ai.enabled', false);

        try {
            $provider = AiProvider::defaultProvider();
        } catch (Throwable) {
            $provider = null;
        }

        return [
            'enabled' => $enabled,
            'provider' => $provider?->name,
            'driver' => $provider?->driver?->label(),
            'model' => $provider?->model,
            // Whether a credential exists. Never which one, never a fingerprint on this page.
            'has_key' => $provider?->hasApiKey() ?? false,
            'base_url' => $provider?->base_url,
            'configured' => $enabled && $provider !== null,
        ];
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    public static function setting(string $key): mixed
    {
        try {
            return app(Settings::class)->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    public static function count(string $table): ?int
    {
        try {
            return DB::table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    public static function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = 0;
        $value = (float) $bytes;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return ($index === 0 ? (string) (int) $value : number_format($value, $value < 10 ? 1 : 0)).' '.$units[$index];
    }

    public static function isWritable(string $path): bool
    {
        try {
            return File::exists($path) && File::isWritable($path);
        } catch (Throwable) {
            return false;
        }
    }

    public static function publicStorageUrl(): string
    {
        try {
            return Storage::disk('public')->url('');
        } catch (Throwable) {
            return '/storage';
        }
    }

    private static function iniValue(string $key): string
    {
        $value = ini_get($key);

        return is_string($value) && $value !== '' ? $value : __('Unknown');
    }

    private static function bytesFromIni(string $key): ?int
    {
        $value = ini_get($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        $value = trim($value);
        $unit = mb_strtolower(mb_substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function databaseName(array $config): string
    {
        $database = $config['database'] ?? null;

        if (! is_string($database) || $database === '') {
            return __('Unknown');
        }

        // A SQLite path is a filename, not a database name, and the full path is noise.
        return $config['driver'] === 'sqlite' ? basename($database) : $database;
    }

    private static function driverLabel(string $driver): string
    {
        return match ($driver) {
            'mysql' => 'MySQL / MariaDB',
            'pgsql' => 'PostgreSQL',
            'sqlite' => 'SQLite',
            'sqlsrv' => 'SQL Server',
            default => $driver,
        };
    }
}
