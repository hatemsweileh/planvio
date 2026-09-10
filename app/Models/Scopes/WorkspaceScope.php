<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentWorkspace;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Constrains tenant-scoped models to the workspace bound on {@see CurrentWorkspace}.
 *
 * The scope is deliberately inert when nothing is bound so that installer, console and
 * admin contexts can operate across tenants. It is convenience, never the authority —
 * policies re-verify workspace membership independently (ARCHITECTURE.md §3).
 *
 * @implements Scope<Model>
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspaceId = Container::getInstance()->make(CurrentWorkspace::class)->id();

        if ($workspaceId === null) {
            return;
        }

        $builder->where($model->qualifyColumn('workspace_id'), $workspaceId);
    }
}
