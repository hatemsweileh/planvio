<?php

declare(strict_types=1);

namespace App\Actions\Dependencies;

use App\Events\Tasks\TaskDependencyDeleted;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes one edge from the dependency graph.
 *
 * Removing an edge can never create a cycle, so there is nothing to verify here beyond the
 * row still existing. Deleting an edge that is already gone is silent.
 */
final class DeleteDependency
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(TaskDependency $dependency, User $actor): TaskDependency
    {
        if (! $dependency->exists) {
            return $dependency;
        }

        $dependsOnId = (int) $dependency->depends_on_task_id;

        $task = $dependency->relationLoaded('task')
            ? $dependency->getRelation('task')
            : $dependency->task()->first();

        DB::transaction(function () use ($dependency, $actor, $task, $dependsOnId): void {
            if ($task instanceof Task) {
                $this->activity->record($task, 'dependency_removed', $actor, [
                    'dependency_id' => (int) $dependency->getKey(),
                    'depends_on_task_id' => $dependsOnId,
                    'type' => $dependency->type->value,
                ]);
            }

            $dependency->delete();
        });

        if ($task instanceof Task) {
            event(new TaskDependencyDeleted($task, $dependsOnId, $actor));
        }

        return $dependency;
    }
}
