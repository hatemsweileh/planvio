<?php

declare(strict_types=1);

namespace App\Services\Install;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\ServiceProvider;

/**
 * Wiring for the installer, and the one hook that lets an unconfigured Planvio boot at all.
 *
 * It lives beside the classes it binds rather than in `App\Providers` so that the whole
 * installer — engine, screens excepted — is one directory somebody can read end to end.
 *
 * `register()` is deliberate. {@see InstallerEnvironment::prepare()} has to run before any
 * other provider boots and long before the first middleware, because on a fresh extract
 * there is no `APP_KEY` for `EncryptCookies` and no database for the session handler. The
 * register phase is the earliest point at which configuration is loaded and nothing has been
 * resolved yet, which is exactly the window it needs. This provider is therefore listed first
 * in `bootstrap/providers.php`.
 *
 * On an installed application every one of these bindings is inert — nothing resolves them —
 * and `prepare()` returns on its first line.
 */
final class InstallServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        InstallerEnvironment::prepare($this->app);

        $this->app->singleton(InstallPaths::class, static fn (): InstallPaths => InstallPaths::default());

        $this->app->singleton(InstallCheckpoint::class, static fn ($app): InstallCheckpoint => new InstallCheckpoint(
            $app->make(InstallPaths::class)->checkpoint,
        ));

        $this->app->singleton(InstallState::class, static fn ($app): InstallState => new InstallState(
            $app->make(Session::class),
            $app->make(InstallCheckpoint::class),
        ));
    }
}
