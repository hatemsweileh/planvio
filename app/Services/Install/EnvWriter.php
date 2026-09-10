<?php

declare(strict_types=1);

namespace App\Services\Install;

use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Throwable;

/**
 * Writes the installation's `.env`.
 *
 * It starts from whatever is already on disk — the bootstrap file
 * {@see InstallerEnvironment} created on the first request — and, failing that, from
 * `.env.example`. Both paths keep the shipped comments, which are the only documentation an
 * administrator has when they later want to raise the upload limit or turn on 2FA.
 *
 * The file is written to a temporary name in the same directory and renamed into place. A
 * half-written `.env` is the worst possible failure mode here: the application would boot
 * with a truncated `APP_KEY`, or with none, and the wizard that could fix it needs the file
 * to load in order to run. `rename()` within one directory is atomic on every filesystem
 * Planvio is supported on.
 */
final class EnvWriter
{
    public function __construct(private readonly InstallPaths $paths) {}

    /**
     * The key this installation will keep.
     *
     * An existing key is reused rather than replaced. It was generated with the same entropy,
     * and rotating it here would invalidate the session cookie the wizard is running on —
     * which would sign the administrator out of the installer somewhere around step nine.
     */
    public function appKey(): string
    {
        $configured = config('app.key');

        if (is_string($configured) && trim($configured) !== '' && $this->isRandom($configured)) {
            return $configured;
        }

        $onDisk = EnvFile::fromFile($this->paths->env)->get('APP_KEY');

        if (is_string($onDisk) && $this->isRandom($onDisk)) {
            return $onDisk;
        }

        return $this->generate();
    }

    public function write(InstallPlan $plan, string $appKey): void
    {
        $file = is_file($this->paths->env)
            ? EnvFile::fromFile($this->paths->env)
            : EnvFile::fromFile($this->paths->envExample());

        $file->fill($plan->environmentValues($appKey));

        $this->put($file->render());
    }

    /**
     * Record in `.env` that the installation is finished.
     *
     * Separate from {@see self::write()} because it happens ten steps later. `APP_INSTALLED`
     * is the hint a scripted deployment can set without touching `storage/`, so it has to
     * become true — but only once it is true, which is the moment the lock is written. Until
     * then the wizard has to keep looking uninstalled to its own middleware.
     *
     * Returns false rather than throwing: by the time this is called the lock exists, and a
     * hint that could not be written must not undo a finished installation.
     */
    public function markInstalled(): bool
    {
        if (! is_file($this->paths->env)) {
            return false;
        }

        try {
            $this->put(EnvFile::fromFile($this->paths->env)->set('APP_INSTALLED', 'true')->render());
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Whether a key is a real random key rather than the deterministic stand-in
     * {@see InstallerEnvironment} falls back to when `.env` cannot be written at all.
     *
     * Reusing that one would hand every installation on the same absolute path an identical
     * key, so it is treated as no key and replaced.
     */
    private function isRandom(string $key): bool
    {
        return $key !== $this->deterministicKey();
    }

    private function deterministicKey(): string
    {
        $cipher = config('app.cipher');

        return InstallerEnvironment::fallbackKey($this->paths->base, is_string($cipher) ? $cipher : null);
    }

    private function generate(): string
    {
        $cipher = config('app.cipher');

        return 'base64:'.base64_encode(
            Encrypter::generateKey(is_string($cipher) && $cipher !== '' ? $cipher : 'AES-256-CBC'),
        );
    }

    private function put(string $contents): void
    {
        $directory = dirname($this->paths->env);

        if (! is_dir($directory)) {
            throw new RuntimeException("The directory [{$directory}] does not exist.");
        }

        $temporary = $this->paths->env.'.planvio-'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Could not write to [{$directory}].");
        }

        @chmod($temporary, 0600);

        if (! @rename($temporary, $this->paths->env)) {
            @unlink($temporary);

            throw new RuntimeException("Could not replace [{$this->paths->env}].");
        }

        @chmod($this->paths->env, 0600);
    }
}
