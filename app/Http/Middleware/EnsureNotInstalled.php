<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the installer once the installation exists.
 *
 * The wizard writes `.env`, creates the first platform administrator and runs migrations.
 * Leaving it reachable afterwards would hand any visitor a way to point a live install at a
 * database of their own choosing, so the guard is server-side and every installer route
 * carries it — hiding the link is not a control.
 *
 * Like {@see EnsureInstalled}, it answers from the filesystem and never queries the database.
 */
final class EnsureNotInstalled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! EnsureInstalled::isInstalled()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, __('Planvio is already installed.'));
        }

        return redirect()->to('/');
    }
}
