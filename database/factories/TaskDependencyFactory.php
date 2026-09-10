<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DependencyType;
use App\Models\Task;
use App\Models\TaskDependency;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<TaskDependency>
 */
final class TaskDependencyFactory extends Factory
{
    /**
     * @var class-string<TaskDependency>
     */
    protected $model = TaskDependency::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => function (array $attributes): int {
                return $this->taskIdFor($attributes);
            },
            // The far end is built inside the same project: a dependency that reaches
            // across workspaces is the exact thing the tenancy tests exist to catch, so
            // the factory never produces one by accident.
            'depends_on_task_id' => static function (array $attributes): int {
                $task = Task::withoutWorkspaceScope()->findOrFail($attributes['task_id']);

                return (int) Task::factory()->create([
                    'workspace_id' => $task->workspace_id,
                    'project_id' => $task->project_id,
                ])->getKey();
            },
            'type' => DependencyType::FinishToStart,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TaskDependency $dependency): void {
            $dependency->workspace_id ??= Task::withoutWorkspaceScope()
                ->whereKey($dependency->task_id)
                ->value('workspace_id');
        });
    }

    public function ofType(DependencyType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    public function blocks(): static
    {
        return $this->ofType(DependencyType::Blocks);
    }

    public function relatesTo(): static
    {
        return $this->ofType(DependencyType::RelatesTo);
    }

    /**
     * Both ends of the edge, stated explicitly.
     */
    public function between(Task $task, Task $dependsOn): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->getKey(),
            'depends_on_task_id' => $dependsOn->getKey(),
        ]);
    }

    /**
     * A task in the workspace the caller pinned, or a fresh one when they pinned none.
     *
     * @param array<string, mixed> $attributes
     */
    private function taskIdFor(array $attributes): int
    {
        $workspaceId = $this->pinnedWorkspaceId($attributes);
        $factory = Task::factory();

        if ($workspaceId !== null) {
            $factory = $factory->state(['workspace_id' => $workspaceId]);
        }

        return (int) $factory->create()->getKey();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function pinnedWorkspaceId(array $attributes): ?int
    {
        if (! isset($attributes['workspace_id'])) {
            return null;
        }

        $workspace = value($attributes['workspace_id']);

        if ($workspace instanceof Model) {
            return (int) $workspace->getKey();
        }

        return is_numeric($workspace) ? (int) $workspace : null;
    }
}
