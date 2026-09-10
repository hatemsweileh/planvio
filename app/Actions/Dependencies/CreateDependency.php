<?php

declare(strict_types=1);

namespace App\Actions\Dependencies;

use App\Enums\DependencyType;
use App\Events\Tasks\TaskDependencyCreated;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Declares that one task waits for another.
 *
 * The rule this action exists to enforce is that the graph stays acyclic. Nothing in the
 * schema can do it: `unique(task_id, depends_on_task_id)` rejects the same edge twice but
 * has no opinion about A waiting for B waiting for C waiting for A. So before the row is
 * written, the graph is searched for a path that already leads from the dependency back to
 * the task — if one exists, the new edge would close it into a ring and the write is
 * refused, naming the chain.
 *
 * The check covers every edge type, not only the blocking ones. `relates_to` is stored in
 * the same directed table and walked by the same code, so a ring built out of it hangs the
 * same walks.
 *
 * Asking twice for the same edge returns the existing row. Asking for the same pair with a
 * different type updates the type in place, because the unique index makes a second row
 * impossible and silently ignoring the request would be worse.
 */
final class CreateDependency
{
    public function __construct(
        private readonly DependencyGraph $graph,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(
        Task $task,
        Task $dependsOn,
        User $actor,
        DependencyType $type = DependencyType::FinishToStart,
    ): TaskDependency {
        $taskId = (int) $task->getKey();
        $dependsOnId = (int) $dependsOn->getKey();

        if ($taskId === $dependsOnId) {
            throw new SelfDependency($task);
        }

        if ((int) $task->workspace_id !== (int) $dependsOn->workspace_id) {
            throw new DependencyAcrossWorkspaces($task, $dependsOn);
        }

        return DB::transaction(function () use ($task, $dependsOn, $actor, $type, $taskId, $dependsOnId): TaskDependency {
            $existing = TaskDependency::query()
                ->where('task_id', $taskId)
                ->where('depends_on_task_id', $dependsOnId)
                ->first();

            if ($existing instanceof TaskDependency) {
                return $this->reconcile($existing, $task, $actor, $type);
            }

            $this->assertNoCycle($task, $dependsOn, $taskId, $dependsOnId);

            $dependency = TaskDependency::query()->create([
                'workspace_id' => $task->workspace_id,
                'task_id' => $taskId,
                'depends_on_task_id' => $dependsOnId,
                'type' => $type,
            ]);

            $this->activity->record($task, 'dependency_added', $actor, [
                'dependency_id' => (int) $dependency->getKey(),
                'depends_on_task_id' => $dependsOnId,
                'type' => $type->value,
            ]);

            event(new TaskDependencyCreated($dependency, $actor));

            return $dependency;
        });
    }

    /**
     * A path from the dependency back to the task means the task is already, transitively,
     * something the dependency waits for. Adding the edge would close the ring.
     */
    private function assertNoCycle(Task $task, Task $dependsOn, int $taskId, int $dependsOnId): void
    {
        $path = $this->graph->pathBetween($dependsOnId, $taskId);

        if ($path === null) {
            return;
        }

        // Reported as the loop the person would create, not as the path that already exists:
        // the new edge closes it, so the task appears at both ends.
        $cycle = [$taskId, ...$path];

        throw new DependencyCycleDetected($task, $dependsOn, $cycle, $this->graph->describe($cycle));
    }

    private function reconcile(
        TaskDependency $existing,
        Task $task,
        User $actor,
        DependencyType $type,
    ): TaskDependency {
        if ($existing->type === $type) {
            return $existing;
        }

        $previous = $existing->type;

        $existing->type = $type;
        $existing->save();

        $this->activity->record($task, 'dependency_updated', $actor, [
            'dependency_id' => (int) $existing->getKey(),
            'depends_on_task_id' => (int) $existing->depends_on_task_id,
            'attribute' => 'type',
            'old' => $previous->value,
            'new' => $type->value,
        ]);

        return $existing;
    }
}
