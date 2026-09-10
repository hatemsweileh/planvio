<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Database-backed maintenance mode.
 *
 * Laravel's own `php artisan down` needs shell access and writes a file the web user may
 * not be able to remove. Planvio is installed by uploading a ZIP to shared hosting, so the
 * switch lives in the settings table where the admin panel can reach it.
 *
 * Platform administrators keep full access — the point of the mode is to work on the
 * installation, which is impossible if the person doing the work is locked out too. The
 * sign-in screens stay open for the same reason: an administrator who is not yet
 * authenticated has to be able to become one.
 */
final class MaintenanceMode
{
    /** Roughly "check back later", not a promise. */
    private const RETRY_AFTER_SECONDS = 3600;

    /**
     * Routes that must answer while the mode is engaged, so an administrator can sign in
     * and turn it off again.
     *
     * @var list<string>
     */
    private const EXEMPT_ROUTES = [
        'login',
        'login.store',
        'logout',
        'two-factor.login',
        'two-factor.login.store',
        'password.request',
        'password.email',
        'password.reset',
        'password.store',
    ];

    /**
     * @var list<string>
     */
    private const EXEMPT_PATHS = [
        'install',
        'install/*',
        'up',
        'build/*',
        'css/*',
        'js/*',
        'img/*',
        'fonts/*',
        'design/*',
        'favicon.ico',
        'favicon.svg',
        'robots.txt',
        'site.webmanifest',
    ];

    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->engaged()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && $user->isPlatformAdmin() && $user->is_active) {
            return $next($request);
        }

        if ($request->is(self::EXEMPT_PATHS) || $request->routeIs(self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        $message = $this->message();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
                ->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
        }

        return response()
            ->view('errors.503', ['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
            ->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
    }

    private function engaged(): bool
    {
        $key = config('planvio.maintenance.setting_key');

        if (! is_string($key) || $key === '') {
            return false;
        }

        return filter_var($this->settings->get($key, false), FILTER_VALIDATE_BOOL);
    }

    private function message(): string
    {
        $key = config('planvio.maintenance.message_key');
        $stored = is_string($key) && $key !== '' ? $this->settings->get($key) : null;

        if (is_string($stored) && trim($stored) !== '') {
            return trim($stored);
        }

        return __('Planvio is temporarily unavailable while maintenance is carried out. Please try again shortly.');
    }
}
