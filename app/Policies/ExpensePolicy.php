<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use App\Policies\Concerns\ChecksWorkspaceAccess;

/**
 * Money is read under `budget.view` — which a workspace manager holds only inside a project
 * they manage (`+`) — and written under `budget.manage`, which stops at owner and admin. A
 * project manager can therefore see the spend without being able to change it.
 */
final class ExpensePolicy
{
    use ChecksWorkspaceAccess;

    public function viewAny(User $user, ?Project $project = null): bool
    {
        return $this->permitsSomewhere(
            $user,
            $this->contextWorkspace($project),
            Permission::BudgetView,
        );
    }

    public function view(User $user, Expense $expense): bool
    {
        return $this->permits(
            $user,
            $expense->workspace_id,
            Permission::BudgetView,
            $this->projectIdOf($expense),
        );
    }

    public function create(User $user, ?Project $project = null): bool
    {
        return $this->permits(
            $user,
            $this->contextWorkspace($project),
            Permission::BudgetManage,
            $project,
        );
    }

    public function update(User $user, Expense $expense): bool
    {
        return $this->manages($user, $expense);
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $this->manages($user, $expense);
    }

    public function restore(User $user, Expense $expense): bool
    {
        return $this->manages($user, $expense);
    }

    public function forceDelete(User $user, Expense $expense): bool
    {
        return $this->manages($user, $expense);
    }

    private function manages(User $user, Expense $expense): bool
    {
        return $this->permits(
            $user,
            $expense->workspace_id,
            Permission::BudgetManage,
            $this->projectIdOf($expense),
        );
    }
}
