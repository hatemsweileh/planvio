<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Locale;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\Install\InstallState;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides what language this request renders in, and which way it reads.
 *
 * ## The order, most specific first
 *
 *   1. the signed-in person's own `users.locale`;
 *   2. the workspace they are in (`workspaces.locale`) — a shared space has a house
 *      language, and somebody who never set a preference should get it;
 *   3. the `locales` row flagged default;
 *   4. `config('app.locale')`, which is what the installer wrote.
 *
 * Each step is skipped when it names a language this installation does not offer, rather
 * than accepted and then failed on. That is the point of the `locales` table: `users.locale`
 * and `workspaces.locale` are plain columns that nothing stops holding `de` after German was
 * removed, and a column is not permission to render in a language. Nothing here ever reads a
 * locale out of the request — no `?lang=`, no `Accept-Language` — because a language is a
 * stored preference, and honouring a query parameter would let one link change what a
 * subsequent form submission says it agreed to.
 *
 * ## Where it runs
 *
 * Appended to the `web` group, and registered as Livewire persistent middleware for the same
 * reason {@see SetCurrentWorkspace} is: `POST /livewire/update` carries the `web` group, not
 * the middleware of the page the component is sitting on, so without this an Arabic user's
 * second interaction with any component would come back in English — and, worse, would come
 * back left-to-right inside a right-to-left page.
 *
 * In the web group this runs *ahead* of the tenant middleware, because Laravel assembles
 * group middleware before route middleware and its priority sort only ever moves middleware
 * earlier. So step 2 asks {@see CurrentWorkspace} first — correct whenever the tenant is
 * already bound — and otherwise resolves the route's workspace itself, with the membership
 * check that makes reading anything out of that row legitimate.
 */
final class SetLocale
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Where the wizard's own language is kept.
     *
     * Deliberately not under `planvio.installer`, which {@see InstallState::forget()}
     * drops wholesale when somebody presses Start over: restarting the wizard should not
     * also put it back into a language the person cannot read.
     */
    public const INSTALLER_SESSION_KEY = 'planvio.installer_language';

    public function handle(Request $request, Closure $next): Response
    {
        $locales = Locale::enabledByCode();

        $locale = $locales->isEmpty()
            ? $this->beforeInstallation($request)
            : $this->resolve($request, $locales);

        if ($locale instanceof Locale) {
            App::setLocale((string) $locale->code);
        }

        // Shared even when no locale row applies: a layout that has to ask whether the
        // variable exists before it can set `dir` would get it wrong on exactly the pages
        // that render before the catalogue is seeded.
        View::share('textDirection', $locale?->isRtl() ? Locale::RTL : Locale::LTR);

        return $next($request);
    }

    /**
     * The language of the installation wizard, before there is a `locales` table to ask.
     *
     * Until `migrate` has run, every step rendered in `config('app.locale')` with no
     * direction resolved at all — so an installation whose `.env` already said `ar` came out
     * as Arabic text laid out left to right, and a customer who had only just extracted the
     * ZIP had no way to choose a language in the first place. The picker in the installer
     * shell writes a code here and this reads it back, out of {@see Locale::SHIPPED} rather
     * than out of a table that does not exist yet.
     *
     * It is a session value rather than a query parameter, which is the rule in the class
     * docblock rather than an exception to it: a language is a stored preference, set by a
     * form the person at the keyboard submitted, not something a link they were sent can
     * change.
     *
     * Only while the installation is unfinished. Afterwards an empty `locales` table means
     * the database is unreachable, and a stale wizard session must not be allowed to decide
     * what a running installation renders in.
     */
    private function beforeInstallation(Request $request): ?Locale
    {
        if (EnsureInstalled::isInstalled() || ! $request->hasSession()) {
            return null;
        }

        $shipped = Locale::shipped();

        return $this->offered($shipped, $request->session()->get(self::INSTALLER_SESSION_KEY))
            ?? $this->offered($shipped, config('app.locale'));
    }

    /**
     * @param Collection<string, Locale> $locales
     */
    private function resolve(Request $request, Collection $locales): ?Locale
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        return $this->offered($locales, $user?->locale)
            ?? $this->offered($locales, $this->workspace($request, $user)?->locale)
            ?? $this->fallback($locales);
    }

    /**
     * The stored code, but only when this installation actually offers it.
     *
     * @param Collection<string, Locale> $locales
     */
    private function offered(Collection $locales, mixed $code): ?Locale
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        return $locales->get($code);
    }

    /**
     * @param Collection<string, Locale> $locales
     */
    private function fallback(Collection $locales): ?Locale
    {
        return $locales->firstWhere('is_default', true)
            ?? $this->offered($locales, config('app.locale'))
            ?? null;
    }

    /**
     * The workspace whose house language applies, or null when there is none to speak of.
     */
    private function workspace(Request $request, ?User $user): ?Workspace
    {
        $bound = $this->current->get();

        if ($bound instanceof Workspace) {
            return $bound;
        }

        if ($user === null) {
            return null;
        }

        $parameter = $request->route()?->parameter('workspace');

        $workspace = match (true) {
            $parameter instanceof Workspace => $parameter,
            is_string($parameter) && $parameter !== '' => $this->bySlug($parameter),
            default => null,
        };

        if (! $workspace instanceof Workspace) {
            return null;
        }

        // SetCurrentWorkspace refuses a non-member a few middleware later. Re-checking here
        // rather than trusting the URL keeps that refusal from being preceded by a read of
        // somebody else's workspace row, however harmless a language code looks.
        return $this->isMember($user, $workspace) ? $workspace : null;
    }

    private function bySlug(string $slug): ?Workspace
    {
        try {
            return Workspace::query()->where('slug', $slug)->first();
        } catch (QueryException) {
            return null;
        }
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        try {
            return WorkspaceMember::withoutWorkspaceScope()
                ->where('workspace_id', $workspace->getKey())
                ->where('user_id', $user->getKey())
                ->exists();
        } catch (QueryException) {
            return false;
        }
    }
}
