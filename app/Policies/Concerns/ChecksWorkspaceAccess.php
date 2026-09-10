<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Enums\Permission;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Permissions;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The three-step authorization sequence every policy method runs (ARCHITECTURE.md §4.3).
 *
 *   1. Resolve the acting user's WorkspaceMember for the record's own `workspace_id`.
 *      No membership, no access. The lookup deliberately ignores WorkspaceScope: the scope
 *      is inert until something binds a workspace, and any caller may escape it, so an
 *      authorization decision that trusted it would be no decision at all.
 *   2. Ask the capability matrix whether the workspace role can ever hold the permission.
 *   3. Refine the conditional cells — `+` project manager, `*` guest inside the project,
 *      `~`/`own` the user's own records.
 *
 * Performance: a board renders every row through a policy, so nothing here may query per
 * row. Membership comes from the memoised User::roleIn()/projectRoleIn() helpers, and the
 * project and ownership arguments accept closures that are only invoked when step 3 is
 * actually reached — an owner or admin never pays for a lookup their `Y` cell ignores.
 *
 * Identifiers arrive straight off model attributes, so every entry point takes them loosely
 * and normalises through {@see self::identifier()}: a driver that hands back `"7"` instead
 * of `7` must not turn an authorization check into a TypeError.
 */
trait ChecksWorkspaceAccess
{
    /**
     * The full three-step check for a concrete record.
     *
     * @param Workspace|int|string|null $workspace the record's own workspace, never the ambient one
     * @param Project|int|string|Closure|null $project the project the record lives in, or a
     *                                                 closure resolving it, for `+`/`*`
     * @param bool|Closure|null $owns whether the acting user owns the record, or a closure
     *                                resolving it, for `~`/`own`
     */
    protected function permits(
        User $user,
        mixed $workspace,
        Permission $permission,
        mixed $project = null,
        bool|Closure|null $owns = null,
    ): bool {
        $role = $this->workspaceRole($user, $workspace);

        if ($role === null) {
            return false;
        }

        if (! Permissions::has($role, $permission)) {
            return false;
        }

        if (Permissions::requiresProjectScope($role, $permission)) {
            return $this->satisfiesProjectScope($user, $role, value($project));
        }

        if (Permissions::ownOnly($role, $permission)) {
            return value($owns) === true;
        }

        return true;
    }

    /**
     * Steps 1 and 2 only — "could this user hold the permission anywhere in the workspace".
     *
     * For `viewAny` and for creation intent, where no record exists to refine against. It is
     * safe because it never authorizes a record: an index built on it still runs every row
     * through {@see self::permits()}, and a create form still authorizes the write.
     *
     * @param Workspace|int|string|null $workspace
     */
    protected function permitsSomewhere(User $user, mixed $workspace, Permission $permission): bool
    {
        $role = $this->workspaceRole($user, $workspace);

        return $role !== null && Permissions::has($role, $permission);
    }

    /**
     * The acting user's role in a workspace, or null when they are not a member.
     *
     * A deactivated account holds no role anywhere: disabling a user must revoke their
     * authority everywhere at once, not only at the login screen.
     *
     * @param Workspace|int|string|null $workspace
     */
    protected function workspaceRole(User $user, mixed $workspace): ?WorkspaceRole
    {
        if (! $user->is_active) {
            return null;
        }

        if ($workspace instanceof Workspace) {
            return $workspace->exists ? $user->roleIn($workspace) : null;
        }

        $workspaceId = $this->identifier($workspace);

        if ($workspaceId === null) {
            return null;
        }

        return $user->roleIn($this->workspaceReference($workspaceId));
    }

    /**
     * @param Workspace|int|string|null $workspace
     */
    protected function isMember(User $user, mixed $workspace): bool
    {
        return $this->workspaceRole($user, $workspace) !== null;
    }

    /**
     * @param Project|int|string|null $project
     */
    protected function projectRole(User $user, mixed $project): ?ProjectRole
    {
        if ($project instanceof Project) {
            return $project->exists ? $user->projectRoleIn($project) : null;
        }

        $projectId = $this->identifier($project);

        if ($projectId === null) {
            return null;
        }

        return $user->projectRoleIn($this->projectReference($projectId));
    }

    /**
     * The workspace to judge a class-level check against: the one implied by the caller's
     * context argument, falling back to the workspace bound for the request or job.
     *
     * @param Workspace|Project|int|string|null $context
     */
    protected function contextWorkspace(mixed $context = null): Workspace|int|null
    {
        if ($context instanceof Workspace) {
            return $context;
        }

        if ($context instanceof Project) {
            return $this->identifier($context->workspace_id);
        }

        return $this->identifier($context) ?? $this->currentWorkspace();
    }

    /**
     * The workspace bound on {@see CurrentWorkspace} — the product UI's tenant. Null in the
     * admin panel, the console and unbound jobs, which is a meaningful answer, not a gap.
     */
    protected function currentWorkspace(): ?Workspace
    {
        return Container::getInstance()->make(CurrentWorkspace::class)->get();
    }

    /**
     * The project a record belongs to: itself when it is a project, otherwise its
     * `project_id`. Null means the record is workspace-level and has no project to refine
     * against, which denies every `+`/`*` cell.
     */
    protected function projectIdOf(?Model $model): ?int
    {
        if ($model === null) {
            return null;
        }

        if ($model instanceof Project) {
            return $this->identifier($model->getKey());
        }

        return $this->identifier($model->getAttribute('project_id'));
    }

    /**
     * The project of a *related* record — a comment's commentable, an attachment's
     * attachable — accepted only when that record sits in the same workspace as the record
     * being authorized. A mismatch means the graph is corrupt or is being probed, and
     * refining a guest's access against a foreign project would be exactly the leak the
     * three layers exist to prevent.
     *
     * @param Workspace|int|string|null $workspace
     */
    protected function relatedProjectId(?Model $related, mixed $workspace): ?int
    {
        if ($related === null) {
            return null;
        }

        $workspaceId = $workspace instanceof Workspace
            ? $this->identifier($workspace->getKey())
            : $this->identifier($workspace);

        if ($workspaceId === null) {
            return null;
        }

        $relatedWorkspaceId = $related instanceof Workspace
            ? $this->identifier($related->getKey())
            : $this->identifier($related->getAttribute('workspace_id'));

        if ($relatedWorkspaceId !== $workspaceId) {
            return null;
        }

        return $this->projectIdOf($related);
    }

    /**
     * Resolve the project behind a polymorphic parent, preferring an already-loaded
     * relation. Only ever called from inside a refinement closure, so a role whose cell is
     * `Y` never pays for the query.
     *
     * @param Workspace|int|string|null $workspace
     */
    protected function morphedProjectId(?Model $loaded, ?string $type, mixed $id, mixed $workspace): ?int
    {
        if ($loaded !== null) {
            return $this->relatedProjectId($loaded, $workspace);
        }

        return $this->relatedProjectId($this->findMorphed($type, $id), $workspace);
    }

    /**
     * Step 3 for the project-scoped cells.
     *
     * `+` appears only in the manager column of the matrix and `*` only in the guest column
     * (ARCHITECTURE.md §4.2), so the acting workspace role is what tells the two apart: a
     * workspace manager has to *manage* the project, a guest merely has to be in it. Any
     * other role reaching here would mean the matrix changed, so it takes the stricter
     * branch rather than the more generous one.
     */
    private function satisfiesProjectScope(User $user, WorkspaceRole $role, mixed $project): bool
    {
        $projectRole = $this->projectRole($user, $project);

        if ($projectRole === null) {
            return false;
        }

        return match ($role) {
            WorkspaceRole::Guest => true,
            default => $projectRole === ProjectRole::Manager,
        };
    }

    /**
     * Load a morph target outside the tenant scope. The scope is inert in jobs and would
     * otherwise hide the parent; the workspace comparison in {@see self::relatedProjectId()}
     * is what actually keeps this safe.
     */
    private function findMorphed(?string $type, mixed $id): ?Model
    {
        if ($type === null || $type === '' || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $instance */
        $instance = new $class;

        return $instance->newQuery()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->find($id);
    }

    /**
     * A key-only Workspace, enough for User::roleIn(), which reads nothing else. Hydrating
     * the real row would add a query to every authorization check on every listed row.
     */
    private function workspaceReference(int $workspaceId): Workspace
    {
        $workspace = new Workspace;
        $workspace->setAttribute($workspace->getKeyName(), $workspaceId);
        $workspace->exists = true;

        return $workspace;
    }

    private function projectReference(int $projectId): Project
    {
        $project = new Project;
        $project->setAttribute($project->getKeyName(), $projectId);
        $project->exists = true;

        return $project;
    }

    /**
     * A usable positive primary key, or null. Guards against `0`, `''`, `false` and
     * non-numeric identifiers reaching a membership lookup as a wildcard.
     */
    private function identifier(mixed $value): ?int
    {
        if ($value instanceof Model) {
            $value = $value->getKey();
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            return null;
        }

        $identifier = (int) $value;

        return $identifier > 0 ? $identifier : null;
    }
}
