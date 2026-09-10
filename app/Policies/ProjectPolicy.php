<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\Concerns\ChecksWorkspaceAccess;

final class ProjectPolicy
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
     * The project is its own scope: a guest passes only because they are a member of *this*
     * project (`*`), which is why the project itself is handed to the refinement.
     */
    public function view(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectView, $project);
    }

    public function create(User $user, Workspace|Project|null $context = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($context),
            Permission::ProjectCreate,
        );
    }

    public function update(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectUpdate, $project);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectDelete, $project);
    }

    public function restore(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectDelete, $project);
    }

    public function forceDelete(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectDelete, $project);
    }

    public function archive(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectArchive, $project);
    }

    public function unarchive(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectArchive, $project);
    }

    public function manageMembers(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectManageMembers, $project);
    }

    public function manageSettings(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectUpdate, $project);
    }

    public function viewBudget(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::BudgetView, $project);
    }

    public function manageBudget(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::BudgetManage, $project);
    }

    public function viewAllTime(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::TimeViewAll, $project);
    }

    public function viewReports(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ReportsView, $project);
    }

    public function viewActivity(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::ProjectView, $project);
    }

    public function useAi(User $user, Project $project): bool
    {
        return $this->permits($user, $project->workspace_id, Permission::AiUse, $project);
    }
}
