<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * `workspace_id` is nullable here: a null row is a template shipped with Planvio, visible to
 * every workspace and owned by none.
 *
 * That global tier is read-only through the product. Editing one would change what every
 * tenant on the install sees, which is platform administration — reachable only from
 * `/admin`, where no workspace is bound and `Gate::before` lets a platform admin through.
 */
final class ProjectTemplatePolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::ProjectView,
        );
    }

    /**
     * A system template holds no tenant data — it is part of the product — so any member of
     * the workspace being browsed may read it.
     */
    public function view(User $user, ProjectTemplate $template): bool
    {
        if ($template->isGlobal()) {
            return $this->permitsSomewhere($user, $this->currentWorkspace(), Permission::ProjectView);
        }

        return $this->permitsSomewhere($user, $template->workspace_id, Permission::ProjectView);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::TemplatesManage,
        );
    }

    public function update(User $user, ProjectTemplate $template): bool
    {
        return $this->manages($user, $template);
    }

    public function delete(User $user, ProjectTemplate $template): bool
    {
        return $this->manages($user, $template);
    }

    public function duplicate(User $user, ProjectTemplate $template): bool
    {
        return $this->view($user, $template) && $this->create($user);
    }

    /**
     * Turning a template into a project is a project creation, not a template edit — which
     * is what lets a manager start from a template they may not edit.
     */
    public function apply(User $user, ProjectTemplate $template): bool
    {
        return $this->view($user, $template)
            && $this->permitsSomewhere($user, $this->currentWorkspace(), Permission::ProjectCreate);
    }

    private function manages(User $user, ProjectTemplate $template): bool
    {
        if ($template->isGlobal() || $template->is_system) {
            return false;
        }

        return $this->permits($user, $template->workspace_id, Permission::TemplatesManage);
    }
}
