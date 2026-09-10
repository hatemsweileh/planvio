<?php

declare(strict_types=1);

namespace App\Services\Install;

/**
 * Every filesystem location the installer writes to, in one carrier.
 *
 * The installer is the one part of Planvio that creates files outside `storage/`: it writes
 * `.env`, it creates the `public/storage` symlink and it drops the lock that closes the
 * wizard for good. Resolving those paths through `base_path()` at each call site would make
 * the whole engine untestable — a test run would rewrite the developer's own `.env` and
 * lock the working copy — so they are passed in instead.
 *
 * {@see self::default()} is what the container binds; the test suite builds its own instance
 * pointing at a temporary directory.
 */
final readonly class InstallPaths
{
    public function __construct(
        public string $base,
        public string $env,
        public string $public,
        public string $storage,
        public string $lockFile,
        public string $checkpoint,
    ) {}

    public static function default(): self
    {
        $lock = config('planvio.install.lock_file');
        $lock = is_string($lock) && $lock !== ''
            ? $lock
            : storage_path('app/planvio-installed.lock');

        return new self(
            base: base_path(),
            env: base_path('.env'),
            public: public_path(),
            storage: storage_path(),
            lockFile: $lock,
            // Beside the lock rather than at a fixed path, so an installation that moved its
            // lock out of `storage/` keeps both files together.
            checkpoint: dirname($lock).DIRECTORY_SEPARATOR.'planvio-install-progress.json',
        );
    }

    /**
     * The example file shipped in the release, used as the template for a fresh `.env`.
     */
    public function envExample(): string
    {
        return $this->base.DIRECTORY_SEPARATOR.'.env.example';
    }

    public function storagePath(string $relative): string
    {
        return $this->storage.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    public function publicPath(string $relative): string
    {
        return $this->public.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }
}
