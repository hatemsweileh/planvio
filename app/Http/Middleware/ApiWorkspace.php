<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for an API request and binds it (ARCHITECTURE.md §3, layer 2).
 *
 * ## Why a header rather than a path segment
 *
 * A Sanctum token belongs to a *person*, not to a workspace, and that person may sit in
 * several. So every scoped call has to say which tenant it means, and there are only two
 * honest ways to say it: `/api/v1/w/{workspace}/tasks/42` or a header alongside
 * `/api/v1/tasks/42`.
 *
 * Planvio picks the header. The deciding argument is that a resource has exactly one URL:
 * `tasks/42` identifies the same record whatever route reached it, so a stored link, a log
 * line and a bug report all refer to one address. With the tenant in the path a record
 * acquires a second identity, and the two can disagree — `/w/acme/tasks/42` where 42 belongs
 * to `northwind` is a request that has to be caught rather than one that cannot be written.
 * The header also keeps the surface flat, which is what makes `GET /api/v1/tasks` the same
 * shape as `GET /api/v1/projects`.
 *
 * The cost is that the header is mandatory and its absence is an error rather than a guess.
 * Defaulting to "the workspace you used last" would make the same script write into
 * different tenants on different days, which is precisely the failure this refuses to have.
 *
 * ## What is actually enforced here
 *
 * Binding the workspace makes {@see WorkspaceScope} apply, which is convenience. Refusing a
 * non-member is the control, and — exactly as {@see SetCurrentWorkspace} does for the web
 * surface — the membership lookup escapes the scope on purpose: a scoped query asked whether
 * you belong to the workspace it was scoped by would answer its own question.
 *
 * Policies remain the authority (§3). Nothing downstream is allowed to assume that reaching
 * a controller means the record may be read.
 */
final class ApiWorkspace
{
    /** The header carrying the workspace slug or numeric id. */
    public const HEADER = 'X-Planvio-Workspace';

    /** Where the resolved model is parked for the controllers, alongside the binding. */
    public const ATTRIBUTE = 'planvio.workspace';

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // `auth:sanctum` runs in front of this on every route that uses it. The guard is
        // here so a future route group cannot reach tenant resolution unauthenticated.
        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        $reference = trim((string) $request->header(self::HEADER, ''));

        abort_if($reference === '', Response::HTTP_BAD_REQUEST, __(
            'This endpoint is workspace-scoped. Send the :header header with a workspace slug or id.',
            ['header' => self::HEADER],
        ));

        $workspace = $this->resolve($reference);

        // An unknown slug and a slug belonging to somebody else must look identical from
        // outside, so both are 404: a 403 here would confirm that the workspace exists.
        abort_if($workspace === null, Response::HTTP_NOT_FOUND, __('No such workspace.'));
        abort_unless($this->isMember($user, $workspace), Response::HTTP_NOT_FOUND, __('No such workspace.'));

        abort_if(
            $workspace->is_suspended && ! $user->isPlatformAdmin(),
            Response::HTTP_FORBIDDEN,
            __('This workspace has been suspended.'),
        );

        $this->current->set($workspace);
        $request->attributes->set(self::ATTRIBUTE, $workspace);

        return $next($request);
    }

    /**
     * A slug, or a bare integer id. Both are accepted because both are stable identifiers a
     * caller may already hold — the id comes back on every resource, the slug is what a
     * person reads off the URL bar.
     */
    private function resolve(string $reference): ?Workspace
    {
        $query = Workspace::query();

        if (ctype_digit($reference)) {
            return $query->whereKey((int) $reference)->first();
        }

        return $query->where('slug', $reference)->first();
    }

    private function isMember(User $user, Workspace $workspace): bool
    {
        if (! $user->is_active) {
            return false;
        }

        return WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->exists();
    }
}
