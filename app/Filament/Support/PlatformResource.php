<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Closure;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The base every resource in the administration panel extends.
 *
 * # Why authorization is decided here and not by the model policies
 *
 * Planvio's policies answer a tenant question: "is this user a member of the workspace this
 * record belongs to, and does their role carry the permission". That is exactly right inside
 * the product, and exactly wrong here. `Gate::before` grants a platform super-admin everything
 * *except* inside a workspace they never joined (ARCHITECTURE.md §4.2), and the panel's whole
 * job is to administer records belonging to workspaces the administrator is not a member of.
 * Left to the policies, an AiRun list would be permanently empty and every row would 403.
 *
 * The documented resolution is that platform administration happens in `/admin`. So the
 * authorization question this panel actually asks is the one `User::canAccessPanel()` asks:
 * is the caller an active platform super-admin. It is asked again per action rather than
 * assumed from panel entry, because a resource is reachable by URL and an account can be
 * demoted mid-session.
 *
 * This is a narrowing, never a widening: it is `Response::deny()` for everybody else, and it
 * cannot be reached at all without first passing the panel's `Authenticate` middleware and
 * `canAccessPanel()`. Filament's `skipAuthorization()` — which returns "allow" unconditionally
 * — is deliberately not used.
 *
 * # Tenant scope
 *
 * `WorkspaceScope` is inert when no workspace is bound, and nothing binds one on `/admin`.
 * Relying on that would make the panel's correctness depend on the absence of a binding, so
 * every query is dropped out of the scope explicitly instead.
 *
 * @template TModel of Model
 *
 * @extends resource<TModel>
 */
abstract class PlatformResource extends Resource
{
    /**
     * Every authorization question this panel asks, answered in one place.
     *
     * @param TModel|null $record
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return self::administrator() === null
            ? Response::deny(__('Only platform administrators may use the administration panel.'))
            : Response::allow();
    }

    /**
     * @return Builder<TModel>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (array_key_exists(WorkspaceScope::class, $query->getModel()->getGlobalScopes())) {
            $query->withoutGlobalScope(WorkspaceScope::class);
        }

        return $query;
    }

    /**
     * An eager-load constraint that drops the tenant scope, for the same reason
     * {@see self::getEloquentQuery()} does: `->with(['project' => self::acrossWorkspaces()])`.
     *
     * A relation is loaded with its own model's global scopes applied, so a workspace-scoped
     * relation would come back empty if anything ever did bind a tenant on a panel request.
     *
     * @return Closure(Relation<Model, Model, mixed>|Builder<Model>): void
     */
    public static function acrossWorkspaces(): Closure
    {
        return static function (Relation|Builder $query): void {
            if (array_key_exists(WorkspaceScope::class, $query->getModel()->getGlobalScopes())) {
                $query->withoutGlobalScope(WorkspaceScope::class);
            }
        };
    }

    /**
     * The signed-in platform administrator, or null when there is not one.
     *
     * Resolved through the default guard rather than Filament's, so it holds in a console or
     * test context where no panel is current.
     */
    public static function administrator(): ?User
    {
        $user = Auth::user();

        return $user instanceof User && $user->isPlatformAdmin() && $user->is_active
            ? $user
            : null;
    }
}
