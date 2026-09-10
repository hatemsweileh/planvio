<?php

declare(strict_types=1);

namespace App\Actions\Dependencies;

use App\Models\Task;
use DomainException;

/**
 * `task_dependencies` carries a single `workspace_id` for the edge, so an edge between two
 * tenants has no honest value to store — and either side would leak the existence of the
 * other's task. Dependencies may cross projects, never workspaces.
 */
final class DependencyAcrossWorkspaces extends DomainException
{
    public function __construct(
        public readonly Task $task,
        public readonly Task $dependsOn,
    ) {
        parent::__construct(__('actions.dependencies.across_workspaces'));
    }
}
