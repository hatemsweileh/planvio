<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends an unconfigured install to the wizard.
 *
 * Deliberately answers from the filesystem alone. This middleware runs in front of every web
 * request, including the ones made *before* there is a database to talk to: querying one
 * here would turn a missing `.env` into a connection exception on the very screen that
 * exists to write it.
 *
 * The lock file is the authority. `APP_INSTALLED` is a hint the installer also writes, kept
 * because it is the flag a test harness or a scripted deployment can set without touching
 * `storage/`; once configuration is cached `env()` stops answering, and by then the lock
 * file is the only thing that matters anyway.
 */
final class EnsureInstalled
{
    /**
     * Paths that must answer while the application is still unconfigured: the wizard, the
     * Livewire endpoint it is built on, the health probe, and static assets.
     *
     * `livewire/*` covers an installation that has pinned Livewire back to its classic path
     * with `Livewire::setUpdateRoute()`. The path Livewire actually serves by default is not
     * a constant — see {@see self::exempt()}.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        'install',
        'install/*',
        'livewire/*',
        'up',
        'build/*',
        'css/*',
        'js/*',
        'img/*',
        'fonts/*',
        'design/*',
        'storage/*',
        'favicon.ico',
        'favicon.svg',
        'robots.txt',
        'site.webmanifest',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (self::isInstalled() || $request->is(self::exempt())) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(503, __('Planvio has not been installed yet.'));
        }

        return redirect()->to('/install');
    }

    /**
     * {@see self::EXEMPT}, plus wherever this installation's Livewire endpoint actually lives.
     *
     * Livewire 4 no longer serves `/livewire/update`. It derives a per-installation prefix
     * from `APP_KEY` — `/livewire-<8 hex>/…` — so that a scanner cannot find the endpoint by
     * guessing. A literal `livewire/*` therefore matches nothing, and the six Livewire screens
     * of the wizard could not submit: every `wire:click` posted to a path this middleware did
     * not recognise, and was answered with a redirect back to `/install`. The installation
     * could not be completed from a browser at all.
     *
     * Resolving the prefix rather than hard-coding one keeps the obfuscation intact. It reads
     * configuration only — no database, no session — which is the whole constraint on this
     * middleware.
     *
     * @return list<string>
     */
    private static function exempt(): array
    {
        return [...self::EXEMPT, trim(EndpointResolver::prefix(), '/').'/*'];
    }

    /**
     * Shared with {@see EnsureNotInstalled} so both sides of the gate cannot disagree.
     */
    public static function isInstalled(): bool
    {
        $lockFile = config('planvio.install.lock_file');

        if (is_string($lockFile) && $lockFile !== '' && is_file($lockFile)) {
            return true;
        }

        return filter_var(env('APP_INSTALLED', false), FILTER_VALIDATE_BOOL);
    }
}
