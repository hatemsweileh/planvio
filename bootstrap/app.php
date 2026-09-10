<?php

declare(strict_types=1);

use App\Exceptions\DomainException;
use App\Http\Middleware\ApiWorkspace;
use App\Http\Middleware\ContentSecurityPolicy;
use App\Http\Middleware\EnsureInstalled;
use App\Http\Middleware\EnsureNotInstalled;
use App\Http\Middleware\EnsureTwoFactorConfirmed;
use App\Http\Middleware\MaintenanceMode;
use App\Http\Middleware\SetCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackRecentItem;
use App\Http\Resources\ApiError;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'installed' => EnsureInstalled::class,
            'not-installed' => EnsureNotInstalled::class,
            'maintenance' => MaintenanceMode::class,
            'workspace' => SetCurrentWorkspace::class,
            'two-factor' => EnsureTwoFactorConfirmed::class,
            'recent' => TrackRecentItem::class,
            'api.workspace' => ApiWorkspace::class,
        ]);

        /*
         | Both of these are global rather than per-route, and in this order.
         |
         | An install gate applied route by route is one forgotten route away from a page
         | that renders against a database that does not exist yet, and maintenance mode is
         | only meaningful if it covers everything an administrator did not think to list —
         | the Filament panel and the Livewire endpoint included, since both run through the
         | web group. Each middleware exempts the paths it must not block.
         |
         | Installation is checked first: while the application is unconfigured there is no
         | settings table for the maintenance flag to live in.
         */
        /*
         | ContentSecurityPolicy is first so that it wraps the two gates below and every
         | error page they produce: a 503 rendered by MaintenanceMode is still a page in
         | this origin. It only writes a response header, and only when the response does
         | not already carry a policy — which is what leaves the attachment controller's
         | much stricter `default-src 'none'; sandbox` in place on the one route that
         | streams bytes somebody uploaded.
         |
         | `throttle:planvio-web` is last in this list but not last in the stack: Laravel
         | sorts ThrottleRequests against the priority list, which puts it behind
         | StartSession (so the limiter can key on the signed-in user) and behind the two
         | gates prepended to that list below (so an unconfigured install answers 503
         | rather than spending a rate-limit bucket on a database that does not exist).
         */
        /*
         | SetLocale is behind both gates deliberately: an unconfigured or offline
         | installation has no `locales` table to read, and the gates answer before anything
         | asks it for one. It is also registered as Livewire persistent middleware in
         | AppServiceProvider, because `POST /livewire/update` carries this group rather than
         | the middleware of the page the component came from — an update that lost the
         | language would answer an Arabic page in English, which is the same class of bug as
         | an update that loses the tenant.
         */
        $middleware->web(append: [
            ContentSecurityPolicy::class,
            EnsureInstalled::class,
            MaintenanceMode::class,
            SetLocale::class,
            'throttle:planvio-web',
        ]);

        /*
         | The same two gates for `/api/v1`, and for the same reason. An installation that has
         | not been configured has no database to answer from, and a maintenance window that
         | the API drove straight through would not be a maintenance window. Both middleware
         | already answer JSON when the request asks for it.
         */
        $middleware->api(append: [
            EnsureInstalled::class,
            MaintenanceMode::class,
        ]);

        /*
         | Appending is not enough on its own. Laravel sorts a route's assembled stack against
         | the framework's priority list, and `Authenticate` — every `auth` and `auth:sanctum`
         | route — sits high in it, so it is pulled in front of anything appended to the group.
         | On an unconfigured server that means Sanctum reads `personal_access_tokens` before
         | the install gate has spoken: `GET /api/v1/*` with any bearer token answers 500 off a
         | database that does not exist, instead of the 503 EnsureInstalled exists to give, and
         | a signed-in web route lands on `/login` rather than on the wizard.
         |
         | Naming both gates in the priority list ahead of AuthenticatesRequests restores the
         | order the block above describes. They still sit behind StartSession, which is where
         | they belong: EnsureInstalled needs no session, and MaintenanceMode reads the flag
         | from the database it has just been told exists.
         */
        /*
         | SetLocale is named too, between the two gates, and that is what makes the
         | maintenance page speak the reader's language. MaintenanceMode does not call the
         | next middleware — it answers 503 with `errors.503` — so wherever it is hoisted to,
         | everything behind it is skipped. Appended after it, SetLocale never runs during a
         | maintenance window, and the one page an Arabic administrator sees while the
         | installation is closed rendered in English and left to right, with no
         | `$textDirection` shared for the layout to put in `dir`.
         |
         | Between the gates rather than in front of them: EnsureInstalled has already
         | confirmed there is a `locales` table to read by the time this runs, and
         | MaintenanceMode reads the settings table immediately afterwards, so nothing here
         | asks an unconfigured server for a database it does not have. It stays behind
         | StartSession with both gates, which is what lets it resolve the signed-in
         | person's own language.
         */
        $middleware->prependToPriorityList(AuthenticatesRequests::class, MaintenanceMode::class);
        $middleware->prependToPriorityList(MaintenanceMode::class, SetLocale::class);
        $middleware->prependToPriorityList(SetLocale::class, EnsureInstalled::class);

        $middleware->redirectGuestsTo(static fn (): string => route('login'));
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         | A DomainException is a refused request, not a fault: an Action was asked for
         | something the model cannot represent — the last owner demoted, a dependency that
         | would close a loop, a duplicate project key. The message was written at the throw
         | site for the person who triggered it, so it goes straight back to the form they
         | submitted rather than through a 500 page.
         */
        $exceptions->dontReport(DomainException::class);

        /*
         | The REST API answers failures in one envelope, whatever went wrong: a validation
         | error, a refused policy, a missing record, a throttled token and an unhandled fault
         | all arrive as `{"error": {status, code, message}}` (docs/API.md).
         |
         | Registered before the handlers below because render callbacks are tried in
         | registration order and the first non-null answer wins — so this claims `/api/*`
         | and everything after it keeps the web behaviour it had. ApiError maps the
         | throwable; nothing about the exception except its mapped status and Planvio's own
         | sentence reaches the response (CLAUDE.md rule 4).
         */
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            return $request->is('api/*') ? ApiError::from($e) : null;
        });

        $exceptions->render(function (DomainException $e, Request $request): ?Response {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'context' => $e->context(),
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return back()
                ->withInput($request->except(['password', 'password_confirmation', 'current_password']))
                ->withErrors(['domain' => $e->getMessage()]);
        });
    })->create();
