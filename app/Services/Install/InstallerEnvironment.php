<?php

declare(strict_types=1);

namespace App\Services\Install;

use App\Http\Middleware\EnsureInstalled;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Throwable;

/**
 * Makes an unconfigured Planvio able to render its own installer.
 *
 * The release ZIP deliberately contains no `.env` (`scripts/build-release.php` refuses to
 * package one), so the very first request a customer makes arrives at an application with no
 * `APP_KEY` and no database. That combination cannot serve a page at all: `EncryptCookies`
 * throws without a key, and `SESSION_DRIVER` defaults to `database`, which would send the
 * session handler at a database that does not exist yet.
 *
 * So before the HTTP kernel runs a single middleware, this class does two things and only
 * while the installation lock is absent:
 *
 * 1. **Bootstraps a key.** If `.env` is missing it is created from `.env.example` with a
 *    freshly generated `APP_KEY`; if it exists with an empty key, the key is filled in. The
 *    same value is pushed into the live config, because the configuration for *this* request
 *    was loaded before the file existed.
 * 2. **Pins session, cache and queue to drivers that need no database.** This holds for the
 *    whole wizard, including the requests that come after `.env` has been written with the
 *    final `database` drivers — the flip happens when the lock appears, by which time the
 *    tables exist. Without it the wizard would lose its session halfway through the install
 *    and answer 419 to its own progress poll.
 *
 * It runs from {@see InstallServiceProvider::register()}, which is listed first in
 * `bootstrap/providers.php`: late enough that configuration is loaded, early enough that
 * nothing has resolved the encrypter or opened a session.
 *
 * The guard against running twice is not an optimisation. `config:cache` boots a second
 * application inside this process to read a clean copy of the configuration; without the
 * flag, this class would reach into that copy and bake the installer's own temporary drivers
 * into the cache file every installation writes.
 */
final class InstallerEnvironment
{
    /**
     * What the wizard runs on, whatever `.env` says, until the lock exists.
     *
     * Each of these is here for a reason the wizard would otherwise break on.
     *
     * **The drivers** must need no database. The session and cache defaults are `database`,
     * which is correct for a finished installation and impossible for one that is creating
     * its tables in step four of twelve.
     *
     * **`session.encrypt`** because the wizard's session holds four passwords for the length
     * of an installation — the database, the administrator, SMTP and the AI key — and the
     * file driver would otherwise leave them in plain text under `storage/framework`.
     * Encrypted, that file needs the application key to read, and it stops being readable at
     * all the moment installation finishes and the driver reverts.
     *
     * **`session.cookie`** because the default name is derived from `APP_NAME`, and the
     * wizard *changes* `APP_NAME` halfway through: the request after `.env` is written would
     * look for a cookie called `acme_projects_session`, not find the `planvio_session` it set
     * five steps ago, and start an empty session — losing the plan and answering 419 to its
     * own progress poll. A fixed name for the duration is the whole fix.
     *
     * **`session.secure`** because the administrator enters the address Planvio *will* live
     * at, which is very often `https://` on a domain whose certificate is not issued yet
     * while they are installing over plain HTTP. A secure-flagged cookie is discarded by the
     * browser on that connection, which would end the installation on the spot. It reverts
     * with everything else when the lock is written, and the value it reverts to is the one
     * derived from the address they gave.
     */
    private const BOOTSTRAP_CONFIG = [
        'session.driver' => 'file',
        'session.encrypt' => true,
        'session.cookie' => 'planvio_installer_session',
        'session.secure' => false,
        'cache.default' => 'file',
        'queue.default' => 'sync',
    ];

    private static bool $prepared = false;

    private function __construct() {}

    public static function prepare(Application $app): void
    {
        if (self::$prepared) {
            return;
        }

        self::$prepared = true;

        // Console commands are run by someone who has a shell and can edit `.env` themselves.
        // Creating one behind their back — during `artisan config:cache`, say — would be a
        // surprise, and every automated test boots exactly this way.
        if ($app->runningInConsole() || EnsureInstalled::isInstalled()) {
            return;
        }

        /** @var Repository $config */
        $config = $app->make('config');

        self::configureForInstallation($config);

        if (self::filled($config->get('app.key'))) {
            return;
        }

        $config->set('app.key', self::bootstrapKey($app, $config));
    }

    /**
     * Apply {@see self::BOOTSTRAP_CONFIG}.
     *
     * Separate from {@see self::prepare()} so the settings can be asserted directly: the
     * whole of `prepare()` is a no-op under the console SAPI every test runs in.
     */
    public static function configureForInstallation(Repository $config): void
    {
        foreach (self::BOOTSTRAP_CONFIG as $key => $value) {
            $config->set($key, $value);
        }
    }

    /**
     * Reset the once-only guard. Test-suite affordance; no production caller.
     */
    public static function flush(): void
    {
        self::$prepared = false;
    }

    /**
     * A key this installation can keep.
     *
     * Persisting it matters more than generating it: the wizard spans several requests, and a
     * key that changed between them would invalidate the session cookie it had just set.
     */
    private static function bootstrapKey(Application $app, Repository $config): string
    {
        $paths = InstallPaths::default();
        $key = self::generateKey($config);

        try {
            $file = is_file($paths->env)
                ? EnvFile::fromFile($paths->env)
                : self::template($paths);

            $existing = $file->get('APP_KEY');

            if (self::filled($existing)) {
                return (string) $existing;
            }

            $file->set('APP_KEY', $key);

            if (self::write($paths->env, $file->render())) {
                return $key;
            }
        } catch (Throwable) {
            // Fall through to the last-resort key below: this method exists to get a page
            // rendered, and the page it gets rendered is the one that reports the failure.
        }

        return self::fallbackKey($paths->base, self::stringOrNull($config->get('app.cipher')));
    }

    /**
     * The starting point for a brand-new `.env`.
     *
     * `.env.example` ships in the release and is heavily commented, so it is by far the
     * better template. The inline fallback covers a tree where it was deleted.
     */
    private static function template(InstallPaths $paths): EnvFile
    {
        $file = is_file($paths->envExample())
            ? EnvFile::fromFile($paths->envExample())
            : EnvFile::fromString(implode("\n", [
                '# Written by the Planvio installer. Edit through the application where possible.',
                'APP_NAME=Planvio',
                'APP_ENV=production',
                'APP_KEY=',
                'APP_DEBUG=false',
                'APP_URL=http://localhost',
                'APP_INSTALLED=false',
                'DB_CONNECTION=mysql',
                'DB_HOST=127.0.0.1',
                'DB_PORT=3306',
                'DB_DATABASE=',
                'DB_USERNAME=',
                'DB_PASSWORD=',
                'SESSION_DRIVER=file',
                'CACHE_STORE=file',
                'QUEUE_CONNECTION=sync',
                'MAIL_MAILER=log',
            ]));

        // The template documents the finished state. Until the wizard has run, the database
        // it names does not exist, so the drivers that would reach for it are stood down and
        // the credentials are blanked rather than inherited from someone else's example.
        return $file->fill([
            'APP_KEY' => '',
            'APP_INSTALLED' => 'false',
            'SESSION_DRIVER' => 'file',
            'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'DB_DATABASE' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
            'MAIL_MAILER' => 'log',
        ]);
    }

    private static function generateKey(Repository $config): string
    {
        $cipher = $config->get('app.cipher');

        return 'base64:'.base64_encode(
            Encrypter::generateKey(is_string($cipher) && $cipher !== '' ? $cipher : 'AES-256-CBC'),
        );
    }

    /**
     * The key used when `.env` cannot be written at all.
     *
     * That is not a working installation and never becomes one: with no writable `.env` the
     * requirements screen refuses to let the wizard continue. This key exists so that screen
     * can be *rendered* — an encrypted cookie is needed before any controller runs — and it
     * is replaced by a random one the moment the file becomes writable. Nothing has been
     * created at this point: no account, no workspace, no row of any kind.
     */
    public static function fallbackKey(string $basePath, ?string $cipher): string
    {
        $length = is_string($cipher) && str_contains($cipher, '128') ? 16 : 32;

        return 'base64:'.base64_encode(
            substr(hash('sha256', 'planvio:installer:'.$basePath, true), 0, $length),
        );
    }

    private static function write(string $path, string $contents): bool
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            if (! is_file($path) || ! is_writable($path)) {
                return false;
            }
        }

        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            return false;
        }

        @chmod($path, 0600);

        return true;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
