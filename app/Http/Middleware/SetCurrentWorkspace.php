<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\CurrentWorkspace;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the tenant for the request and binds it (ARCHITECTURE.md §3, layer 2).
 *
 * Two things happen here, and only one of them is security. Binding the workspace makes
 * {@see WorkspaceScope} apply, which is convenience. Refusing a
 * non-member is the control, and it is enforced with a membership lookup that deliberately
 * escapes the scope: asking a scoped query whether the user is a member of the workspace
 * the scope was derived from would answer its own question.
 *
 * A route without a `{workspace}` parameter still gets a binding, taken from the workspace
 * the user touched most recently. The alternative — leaving the scope inert on shared pages
 * such as the inbox or a personal task list — would silently widen every query on them.
 */
final class SetCurrentWorkspace
{
    /**
     * `workspace_members.last_active_at` drives the "last used" fallback and the presence
     * hints in the member list. Neither needs second-level accuracy, and a write on every
     * request would put a row update in front of every page load on shared hosting.
     */
    private const ACTIVITY_INTERVAL_MINUTES = 60;

    /** How many starred projects the sidebar pins before it stops being a shortcut. */
    private const SIDEBAR_FAVOURITES = 8;

    /** And how many recently opened ones sit under them. */
    private const SIDEBAR_RECENTS = 5;

    public function __construct(private readonly CurrentWorkspace $current) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $parameter = $request->route()?->parameter('workspace');

        $workspace = $parameter === null
            ? $this->lastUsed($user)
            : $this->fromRoute($parameter);

        if ($workspace === null) {
            // An unknown slug is a 404: the workspace either does not exist or was deleted,
            // and both must look the same from outside. With no parameter at all the user
            // simply has no workspace yet, which is a legitimate state after registration.
            if ($parameter !== null) {
                abort(404);
            }

            return $next($request);
        }

        $membership = $this->membership($user, $workspace);

        if ($membership === null) {
            abort(403, __('You do not have access to this workspace.'));
        }

        if ($workspace->is_suspended && ! $user->isPlatformAdmin()) {
            abort(403, __('This workspace has been suspended.'));
        }

        $this->current->set($workspace);

        // Hand the resolved model back to the router so controllers and Livewire components
        // receive a Workspace rather than the raw slug, without a second lookup.
        $request->route()?->setParameter('workspace', $workspace);

        $this->touch($membership);
        $this->shareShell($request, $user, $workspace);

        return $next($request);
    }

    /* ------------------------------------------------------------------ *
     * The shell
     * ------------------------------------------------------------------ */

    /**
     * Everything layouts/app.blade.php and its partials read, resolved once per request.
     *
     * The sidebar renders on *every* page, so its cost is paid on every page. Three queries
     * is the whole budget and it buys three things:
     *
     *   1. the workspaces this person belongs to, for the switcher;
     *   2. favourites and recents in a single pass over `projects` — one query, not two,
     *      because the two lists are the same rows selected by different criteria;
     *   3. the unread count on the Inbox badge, as a count rather than a fetch.
     *
     * Sharing rather than querying inside the partials is the point: a Blade include that
     * queries is a query the caller cannot see, and three of them turn every page in the
     * product into a page with a hidden N+1.
     *
     * A Livewire update re-runs this middleware — it is registered as persistent, because a
     * component that lost its tenant halfway through an interaction would silently query
     * unscoped. It does not re-render the layout, though, so on those requests the tenant is
     * bound and shared and the three lists are skipped: paying for the sidebar on every
     * keystroke in the command palette would undo the point of the budget.
     */
    private function shareShell(Request $request, User $user, Workspace $workspace): void
    {
        View::share('workspace', $workspace);

        if ($request->hasHeader('X-Livewire')) {
            return;
        }

        [$favourites, $recents] = $this->sidebarProjects($user, $workspace);

        View::share([
            'sidebarWorkspaces' => $this->switcherWorkspaces($user),
            'sidebarFavourites' => $favourites,
            'sidebarRecents' => $recents,
            'unreadCount' => $this->unreadCount($user, $workspace),
        ]);
    }

    /**
     * The workspaces the switcher offers, ordered the way it lists them.
     *
     * @return Collection<int, Workspace>
     */
    private function switcherWorkspaces(User $user): Collection
    {
        return Workspace::query()
            ->select(['workspaces.id', 'workspaces.name', 'workspaces.slug', 'workspaces.accent_color', 'workspaces.logo_path'])
            ->join('workspace_members', 'workspace_members.workspace_id', '=', 'workspaces.id')
            ->where('workspace_members.user_id', $user->getKey())
            ->orderBy('workspaces.name')
            ->get();
    }

    /**
     * Starred projects and recently opened ones, in one query.
     *
     * Both lists are rows from `projects` narrowed by a per-user table, so they are fetched
     * together and split in PHP. Two queries would be the obvious shape and would cost
     * twice as much on every page in the product.
     *
     * Archived projects are excluded from both: a sidebar is a list of where you are
     * working, and an archive is by definition where you are not.
     *
     * @return array{0: Collection<int, Project>, 1: Collection<int, Project>}
     */
    private function sidebarProjects(User $user, Workspace $workspace): array
    {
        $userId = $user->getKey();
        $morph = (new Project)->getMorphClass();

        $favouritePosition = DB::table('favorites')
            ->select('favorites.position')
            ->whereColumn('favorites.favoritable_id', 'projects.id')
            ->where('favorites.favoritable_type', $morph)
            ->where('favorites.user_id', $userId)
            ->limit(1);

        $lastViewed = DB::table('recent_items')
            ->select('recent_items.viewed_at')
            ->whereColumn('recent_items.viewable_id', 'projects.id')
            ->where('recent_items.viewable_type', $morph)
            ->where('recent_items.user_id', $userId)
            ->limit(1);

        $projects = Project::query()
            ->withoutWorkspaceScope()
            ->select([
                'projects.id',
                'projects.workspace_id',
                'projects.name',
                'projects.slug',
                'projects.key',
                'projects.color',
                'projects.icon',
                'projects.is_archived',
            ])
            ->selectSub($favouritePosition, 'sidebar_favourite_position')
            ->selectSub($lastViewed, 'sidebar_last_viewed_at')
            ->where('projects.workspace_id', $workspace->getKey())
            ->where('projects.is_archived', false)
            ->where(function ($query) use ($favouritePosition, $lastViewed): void {
                $query->whereExists($favouritePosition->clone())
                    ->orWhereExists($lastViewed->clone());
            })
            ->orderByRaw('case when sidebar_favourite_position is null then 1 else 0 end')
            ->orderBy('sidebar_favourite_position')
            ->orderByDesc('sidebar_last_viewed_at')
            ->limit(self::SIDEBAR_FAVOURITES + self::SIDEBAR_RECENTS)
            ->get();

        $favourites = $projects
            ->filter(static fn (Project $project): bool => $project->getAttribute('sidebar_favourite_position') !== null)
            ->take(self::SIDEBAR_FAVOURITES)
            ->values();

        $recents = $projects
            ->filter(static fn (Project $project): bool => $project->getAttribute('sidebar_favourite_position') === null
                && $project->getAttribute('sidebar_last_viewed_at') !== null)
            ->take(self::SIDEBAR_RECENTS)
            ->values();

        return [$favourites, $recents];
    }

    /**
     * Unread notifications that belong to this workspace, plus the account-level ones that
     * belong to no workspace at all — a security notice must not be invisible because you
     * happen to be looking at the wrong tenant.
     */
    private function unreadCount(User $user, Workspace $workspace): int
    {
        return DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->whereNull('read_at')
            ->where(function ($query) use ($workspace): void {
                $query->where('workspace_id', $workspace->getKey())->orWhereNull('workspace_id');
            })
            ->count();
    }

    /**
     * The route parameter, which is a model when the route declared a binding field and a
     * slug otherwise.
     */
    private function fromRoute(mixed $parameter): ?Workspace
    {
        if ($parameter instanceof Workspace) {
            return $parameter;
        }

        if (! is_string($parameter) || $parameter === '') {
            return null;
        }

        return Workspace::query()->where('slug', $parameter)->first();
    }

    /**
     * The workspace this user worked in most recently, skipping suspended tenants so a
     * suspension does not strand somebody on a 403 they cannot navigate away from.
     */
    private function lastUsed(User $user): ?Workspace
    {
        return Workspace::query()
            ->select('workspaces.*')
            ->join('workspace_members', 'workspace_members.workspace_id', '=', 'workspaces.id')
            ->where('workspace_members.user_id', $user->getKey())
            ->where('workspaces.is_suspended', false)
            ->orderByDesc('workspace_members.last_active_at')
            ->orderByDesc('workspace_members.id')
            ->first();
    }

    private function membership(User $user, Workspace $workspace): ?WorkspaceMember
    {
        return WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->first();
    }

    private function touch(WorkspaceMember $membership): void
    {
        $lastActive = $membership->last_active_at;

        if ($lastActive instanceof Carbon
            && $lastActive->gt(Carbon::now()->subMinutes(self::ACTIVITY_INTERVAL_MINUTES))) {
            return;
        }

        $now = Carbon::now();

        // A bare column write: model events would fire listeners for something that is not
        // a domain change, and `updated_at` is not what "last active" means.
        DB::table('workspace_members')
            ->where('id', $membership->getKey())
            ->update(['last_active_at' => $now]);

        $membership->setAttribute('last_active_at', $now);
        $membership->syncOriginalAttribute('last_active_at');
    }
}
