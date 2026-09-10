<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\SavedView;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * A saved view is either personal — its owner's filter, invisible to everybody else — or
 * shared, in which case it is workspace furniture and follows `templates.manage`, the cell
 * that already governs reusable configuration.
 */
final class SavedViewPolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::ProjectView,
        );
    }

    public function view(User $user, SavedView $view): bool
    {
        if ($this->isOwner($user, $view)) {
            return $this->permits($user, $view->workspace_id, Permission::ProjectView, $this->projectIdOf($view));
        }

        if ($view->isPersonal()) {
            return false;
        }

        return $this->permits(
            $user,
            $view->workspace_id,
            Permission::ProjectView,
            $this->projectIdOf($view),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::ProjectView,
            $project,
        );
    }

    /**
     * Publishing a view to the whole workspace is a configuration change, not a personal
     * one, so it needs more than the right to have made it.
     */
    public function share(User $user, SavedView $view): bool
    {
        return $this->permits(
            $user,
            $view->workspace_id,
            Permission::TemplatesManage,
            $this->projectIdOf($view),
        );
    }

    public function update(User $user, SavedView $view): bool
    {
        if ($view->isPersonal()) {
            return $this->isOwner($user, $view) && $this->view($user, $view);
        }

        return $this->share($user, $view);
    }

    public function delete(User $user, SavedView $view): bool
    {
        return $this->update($user, $view);
    }

    /**
     * Pinning is a per-viewer preference on a view they can already see.
     */
    public function pin(User $user, SavedView $view): bool
    {
        return $this->view($user, $view);
    }

    private function isOwner(User $user, SavedView $view): bool
    {
        return $view->user_id !== null && (int) $view->user_id === (int) $user->getKey();
    }
}
