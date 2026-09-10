<?php

declare(strict_types=1);

namespace Tests\Feature\Installer;

use App\Services\Install\InstallCheckpoint;
use App\Services\Install\InstallerEnvironment;
use App\Services\Install\InstallPaths;
use Tests\TestCase;

/**
 * Shared scaffolding for the installer suite.
 *
 * Two things every test here needs, and neither is optional.
 *
 * **A sandbox.** The installer writes `.env`, creates directories, drops a lock file and
 * makes a `public/storage` symlink. Every one of those is aimed at a temporary directory
 * through {@see InstallPaths}, because a suite that wrote the developer's own `.env` — or
 * locked their working copy as "installed" — would be worse than no suite at all.
 *
 * **An uninstalled application.** `phpunit.xml` sets `APP_INSTALLED=true` so the other 1,388
 * tests can reach the product. The installer only exists while that is false, so it is
 * overridden here and restored afterwards.
 *
 * This suite is excluded from the `Feature` suite in `phpunit.xml` and runs last, for exactly
 * these reasons.
 */
abstract class InstallerTestCase extends TestCase
{
    protected string $sandbox;

    protected InstallPaths $paths;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnv = [];

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'planvio-installer-'.bin2hex(random_bytes(6));

        // The tree a customer extracts, so the requirements screen has something real to
        // report on: these are exactly `config('planvio.install.writable_paths')`.
        foreach (['public', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $directory) {
            mkdir($this->sandbox.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $directory), 0777, true);
        }

        $this->setEnvironmentValue('APP_INSTALLED', 'false');

        parent::setUp();

        InstallerEnvironment::flush();

        $this->paths = new InstallPaths(
            base: $this->sandbox,
            env: $this->sandbox.DIRECTORY_SEPARATOR.'.env',
            public: $this->sandbox.DIRECTORY_SEPARATOR.'public',
            storage: $this->sandbox.DIRECTORY_SEPARATOR.'storage',
            lockFile: $this->sandbox.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'
                .DIRECTORY_SEPARATOR.'planvio-installed.lock',
            checkpoint: $this->sandbox.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'
                .DIRECTORY_SEPARATOR.'planvio-install-progress.json',
        );

        // A real template, so the writer is exercised against the file customers actually get.
        copy(base_path('.env.example'), $this->paths->envExample());

        config(['planvio.install.lock_file' => $this->paths->lockFile]);

        $this->app->instance(InstallPaths::class, $this->paths);
        $this->app->instance(InstallCheckpoint::class, new InstallCheckpoint($this->paths->checkpoint));
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $this->originalEnv = [];

        parent::tearDown();

        $this->deleteDirectory($this->sandbox);

        InstallerEnvironment::flush();
    }

    protected function checkpoint(): InstallCheckpoint
    {
        return $this->app->make(InstallCheckpoint::class);
    }

    /**
     * Pretend the installation already happened, without running it.
     */
    protected function writeLockFile(): void
    {
        file_put_contents($this->paths->lockFile, '{"version":"1.0.0"}');
    }

    protected function setEnvironmentValue(string $key, string $value): void
    {
        if (! array_key_exists($key, $this->originalEnv)) {
            $this->originalEnv[$key] = getenv($key);
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            // A symlink to a directory is removed as a link, never walked into.
            if (is_link($child)) {
                @unlink($child) || @rmdir($child);

                continue;
            }

            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
