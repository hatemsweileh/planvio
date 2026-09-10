<?php

declare(strict_types=1);

namespace App\Events\Tasks;

use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A directed edge was added to the dependency graph.
 */
final class TaskDependencyCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly TaskDependency $dependency,
        public readonly User $actor,
    ) {}
}
