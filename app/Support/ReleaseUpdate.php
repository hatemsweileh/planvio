<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\EnsureInstalled;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * Adopts an uploaded release.
 *
 * Planvio never downloads an update. Someone running 1.0.0 uploads the update archive
 * into the directory they already use, and the next request — or the next cron tick,
 * which boots the same providers — finds `settings.app_version` behind {@see Version::app()}.
 * Compiled caches from the old release would keep serving the old configuration, so they
 * are removed, files this release no longer ships are removed, and the stored application
 * version is brought forward.
 *
 * The database version is deliberately left alone. A release that adds migrations has to
 * say so by bumping {@see Version::DB_VERSION}; this class does not run migrations and
 * does not rewrite `settings.db_version`.
 */
final class ReleaseUpdate
{
    /**
     * Files shipped in 1.0.0 and removed since. Extracting an archive over a directory
     * overwrites files and does not delete ones the archive no longer contains, so an
     * in-place update would otherwise keep running a mixture of two releases.
     *
     * @var list<string>
     */
    private const RETIRED = [
        'resources/views/welcome.blade.php',
    ];

    /**
     * Compiled artefacts the old release may have left behind. Sessions and uploads are
     * not in this list: clearing them would sign everyone out and is not part of adopting
     * a new version.
     *
     * @var list<string>
     */
    private const COMPILED = [
        'bootstrap/cache',
        'storage/framework/views',
        'storage/framework/cache/data',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly string $root,
        private readonly ?string $lockFile,
    ) {}

    /**
     * The boot path. Inert in the test suite, because the suite marks itself installed and
     * a real run would rewrite the working tree it is executing from. Inert before the
     * install lock exists, because there is no release to adopt yet.
     */
    public static function applyIfNeeded(): bool
    {
        if (! self::shouldRun(app()->runningUnitTests(), EnsureInstalled::isInstalled())) {
            return false;
        }

        $applied = self::fromApplication()->apply();

        if ($applied && function_exists('opcache_reset')) {
            // The files on disk are already the new release. A worker that keeps the
            // previous compile will keep comparing the old version with itself.
            @opcache_reset();
        }

        return $applied;
    }

    public static function shouldRun(bool $runningUnitTests, bool $installed): bool
    {
        return ! $runningUnitTests && $installed;
    }

    public static function fromApplication(): self
    {
        $lock = config('planvio.install.lock_file');

        return new self(
            app(Settings::class),
            base_path(),
            is_string($lock) && $lock !== '' ? $lock : null,
        );
    }

    /**
     * Brings a directory whose stored application version is older than this code up to
     * {@see Version::app()}. Returns false when there is nothing to do, or when the
     * settings table cannot be written yet.
     */
    public function apply(): bool
    {
        $stored = null;

        try {
            $stored = $this->settings->get('app_version');

            if (is_string($stored) && $this->alreadyCurrent($stored)) {
                return false;
            }

            if ($stored !== null && ! is_string($stored)) {
                return false;
            }

            $this->clearCompiledCaches();
            $this->removeRetiredFiles();
            $this->settings->set('app_version', Version::app());
            $this->refreshLock();
        } catch (Throwable $e) {
            Log::warning('Planvio could not adopt the uploaded release.', [
                'exception' => $e::class,
            ]);

            return false;
        }

        Log::info('Planvio adopted release '.Version::app().'.', [
            'from' => is_string($stored) ? $stored : null,
        ]);

        return true;
    }

    private function alreadyCurrent(string $stored): bool
    {
        // A value that is not a release number is left untouched. Treating it as "older"
        // would clear the caches on every request and never stop, because writing a
        // version over it is not what an administrator stored that value for.
        if (preg_match('/^\d+\.\d+\.\d+/', $stored) !== 1) {
            return true;
        }

        return version_compare($stored, Version::app(), '>=');
    }

    private function clearCompiledCaches(): void
    {
        foreach (self::COMPILED as $relative) {
            $directory = $this->absolute($relative);

            if ($directory === null || ! is_dir($directory) || is_link($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                /** @var SplFileInfo $item */
                if ($item->isLink() || ! $item->isFile() || $item->getFilename() === '.gitignore') {
                    continue;
                }

                $path = $item->getPathname();

                if ($this->insideRoot($path)) {
                    @unlink($path);
                }
            }
        }

        clearstatcache(true);
    }

    private function removeRetiredFiles(): void
    {
        foreach (self::RETIRED as $relative) {
            $path = $this->absolute($relative);

            if ($path === null || ! is_file($path) || is_link($path) || ! $this->insideRoot($path)) {
                continue;
            }

            @unlink($path);
        }
    }

    /**
     * The lock is the install authority. Its `version` is a record of what was installed,
     * so an adopted release updates that field and leaves `db_version` and `installed_at`.
     */
    private function refreshLock(): void
    {
        if ($this->lockFile === null || ! is_file($this->lockFile) || is_link($this->lockFile)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($this->lockFile), true);

        if (! is_array($decoded)) {
            return;
        }

        $decoded['version'] = Version::app();

        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            return;
        }

        @file_put_contents($this->lockFile, $encoded.PHP_EOL, LOCK_EX);
    }

    private function absolute(string $relative): ?string
    {
        $relative = str_replace('\\', '/', $relative);

        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, '..')) {
            return null;
        }

        return $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function insideRoot(string $path): bool
    {
        $root = realpath($this->root);
        $target = realpath($path);

        if ($root === false || $target === false) {
            return false;
        }

        return $target === $root || str_starts_with($target, $root.DIRECTORY_SEPARATOR);
    }
}
