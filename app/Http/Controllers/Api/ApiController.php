<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ApiWorkspace;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SearchService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What every API controller shares: the tenant, the actor, the page size, and — the
 * important one — how a record id becomes a record.
 *
 * ## Why records are not resolved by route-model binding
 *
 * Laravel's implicit binding runs inside `SubstituteBindings`, which is a middleware like
 * any other and therefore subject to middleware ordering. {@see ApiWorkspace} is what binds
 * the tenant, and a binding substituted before it would run its query with
 * {@see WorkspaceScope} inert — resolving `tasks/42` from whichever
 * workspace happens to own row 42. The policy would still refuse it, so this would not be a
 * leak; but it would be a 403 where a 404 belongs, and a 403 confirms that the record exists.
 *
 * Rather than depend on a middleware sort order staying the way it is today, every scoped
 * record is looked up here, explicitly, with `where('workspace_id', …)` written out. That is
 * layer 1 of §3 (the column) applied by hand, and it holds no matter what the scope is doing.
 * Layer 3 — the policy — is then applied by the controller on the record it got back, exactly
 * as the Livewire components do.
 *
 * ## Pagination
 *
 * `per_page` is a request-controlled number, so it is a resource-exhaustion control as much
 * as a convenience: `config('planvio.pagination.api_max')` is a hard ceiling, silently
 * applied rather than rejected, because a client that asks for 10000 wants "as many as
 * possible" and refusing the whole request teaches nobody anything.
 */
abstract class ApiController extends Controller
{
    use AuthorizesRequests;

    /**
     * The tenant resolved by {@see ApiWorkspace}.
     *
     * Only ever called from a route inside the scoped group, where the middleware has
     * already refused a non-member; the abort is a guard against a route being added to the
     * wrong group, not an expected path.
     */
    protected function workspace(Request $request): Workspace
    {
        $workspace = $request->attributes->get(ApiWorkspace::ATTRIBUTE);

        abort_unless($workspace instanceof Workspace, Response::HTTP_BAD_REQUEST, __(
            'This endpoint is workspace-scoped. Send the :header header with a workspace slug or id.',
            ['header' => ApiWorkspace::HEADER],
        ));

        return $workspace;
    }

    /**
     * The token's owner. Every policy check, every Action call and every audit row uses
     * this: a token acts as the person who created it and can never out-rank them.
     */
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        // Deactivating an account has to revoke its authority everywhere at once, not only at
        // the sign-in screen (`ChecksWorkspaceAccess::workspaceRole()` says the same thing
        // from the policy side). Without this, a token minted before the account was switched
        // off would keep answering on the endpoints that ask no policy — `GET /me` among them.
        abort_unless($user->is_active, Response::HTTP_FORBIDDEN, __('This account has been deactivated.'));

        return $user;
    }

    /**
     * Find a workspace-scoped record by id, or 404.
     *
     * @template TModel of Model
     *
     * @param class-string<TModel> $model
     * @param list<string> $with relations to eager-load; lazy loading throws outside production
     * @return TModel
     */
    protected function findInWorkspace(Request $request, string $model, mixed $id, array $with = []): Model
    {
        $key = self::key($id);

        abort_if($key === null, Response::HTTP_NOT_FOUND, __('No such record.'));

        /** @var Builder<TModel> $query */
        $query = $model::query()->withoutGlobalScope(WorkspaceScope::class);

        $record = $query
            ->where('workspace_id', $this->workspace($request)->getKey())
            ->when($with !== [], static fn (Builder $builder): Builder => $builder->with($with))
            ->whereKey($key)
            ->first();

        // A record in another workspace and a record that never existed are the same answer.
        // Anything else confirms the existence of a row the caller may not read.
        abort_if($record === null, Response::HTTP_NOT_FOUND, __('No such record.'));

        return $record;
    }

    /**
     * A workspace member by user id, or 404.
     *
     * `users` carries no `workspace_id` — an account can belong to several tenants — so the
     * membership row is what scopes the lookup. Without this, `assignee_id` on a write would
     * be a way to confirm that any account on the installation exists, and a way to name a
     * stranger as the assignee of your task.
     */
    protected function workspaceMember(Request $request, int $userId): User
    {
        $workspaceId = $this->workspace($request)->getKey();

        $user = User::query()
            ->whereKey($userId)
            ->whereExists(static fn (QueryBuilder $sub): QueryBuilder => $sub->selectRaw('1')
                ->from('workspace_members')
                ->whereColumn('workspace_members.user_id', 'users.id')
                ->where('workspace_members.workspace_id', $workspaceId))
            ->first();

        abort_if($user === null, Response::HTTP_NOT_FOUND, __('No such workspace member.'));

        return $user;
    }

    /**
     * Page a query with the configured default and ceiling.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel> $query
     * @return LengthAwarePaginator<int, TModel>
     */
    protected function paginate(Request $request, Builder $query): LengthAwarePaginator
    {
        return $query->paginate(perPage: $this->perPage($request))->withQueryString();
    }

    /**
     * The page size for this request: what was asked for, clamped to the ceiling.
     */
    protected function perPage(Request $request): int
    {
        $default = self::setting('planvio.pagination.api', 50);
        $max = self::setting('planvio.pagination.api_max', 200);

        $requested = $request->query('per_page');

        if (! is_string($requested) && ! is_int($requested)) {
            return min($default, $max);
        }

        $requested = (string) $requested;

        if (! ctype_digit($requested)) {
            return min($default, $max);
        }

        return max(1, min((int) $requested, $max));
    }

    /**
     * A `%term%` pattern for a `LIKE ? ESCAPE '!'` filter.
     *
     * The escape character is `!` rather than the SQL-standard backslash, for the reason
     * {@see SearchService} gives: MySQL processes backslashes inside string
     * literals and SQLite does not, so `ESCAPE '\\'` means two different things on the two
     * engines Planvio ships against. Without this, a caller filtering for `100%` would match
     * every row in the table.
     */
    protected static function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($term)).'%';
    }

    /**
     * The SQL half of the same filter, to pair with {@see self::likePattern()}.
     *
     * The escape character is written into the statement as a literal because MySQL will not
     * accept a bound parameter in an `ESCAPE` clause. It is a constant of this class and
     * never touches request input; `$column` is likewise always a literal written at the call
     * site, never a value from the caller.
     */
    protected static function likeSql(string $column): string
    {
        return $column." like ? escape '!'";
    }

    /**
     * A sort column chosen from an allow-list, never from the request.
     *
     * `orderBy` interpolates its argument into SQL, so a column name that came from a query
     * string is an injection point. The caller picks a key; the caller never names a column.
     *
     * @param array<string, string> $allowed request key => column
     * @return array{0: string, 1: string} column and direction
     */
    protected function sort(Request $request, array $allowed, string $default): array
    {
        $requested = $request->query('sort');
        $key = is_string($requested) && isset($allowed[$requested]) ? $requested : $default;

        $direction = $request->query('direction');
        $direction = is_string($direction) && strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return [$allowed[$key], $direction];
    }

    /**
     * A primary key from a route segment. Planvio's ids are big increments, so anything that
     * is not a positive integer is not an id and must not reach the database as one.
     */
    private static function key(mixed $id): ?int
    {
        if (is_int($id)) {
            return $id > 0 ? $id : null;
        }

        if (is_string($id) && ctype_digit($id) && $id !== '0') {
            return (int) $id;
        }

        return null;
    }

    private static function setting(string $key, int $fallback): int
    {
        $value = config($key);

        return is_int($value) && $value > 0 ? $value : $fallback;
    }
}
