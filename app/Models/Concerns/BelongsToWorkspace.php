<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant column + scope layers for a workspace-owned model (ARCHITECTURE.md §3).
 *
 * Registers {@see WorkspaceScope} and back-fills `workspace_id` on create from the
 * workspace bound on {@see CurrentWorkspace}.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope);

        static::creating(static function (Model $model): void {
            if ($model->getAttribute('workspace_id') !== null) {
                return;
            }

            $workspaceId = Container::getInstance()->make(CurrentWorkspace::class)->id();

            if ($workspaceId !== null) {
                $model->setAttribute('workspace_id', $workspaceId);
            }
        });
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Restrict to one workspace explicitly. Additive: it narrows, it never widens, so pair it
     * with `withoutWorkspaceScope()` when querying outside the bound tenant.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeForWorkspace(Builder $query, Workspace|int $workspace): Builder
    {
        return $query->where(
            $this->qualifyColumn('workspace_id'),
            $workspace instanceof Workspace ? $workspace->getKey() : $workspace,
        );
    }

    /**
     * Escape hatch for admin/system code — `Model::query()->withoutWorkspaceScope()`.
     *
     * Exists as a named scope so it is reachable from an existing builder as well as
     * statically off the model.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeWithoutWorkspaceScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(WorkspaceScope::class);
    }

    /**
     * @return Builder<static>
     */
    public static function withoutWorkspaceScope(): Builder
    {
        return static::query()->withoutGlobalScope(WorkspaceScope::class);
    }
}
