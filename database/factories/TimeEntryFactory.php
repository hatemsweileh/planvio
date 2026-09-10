<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TimeEntry>
 */
final class TimeEntryFactory extends Factory
{
    /**
     * @var class-string<TimeEntry>
     */
    protected $model = TimeEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `task_id` resolves before `project_id` so pinning a task — `->for($task)` or
            // forTask() — also settles which project the entry belongs to.
            'task_id' => null,
            'project_id' => function (array $attributes): int {
                return $this->projectIdFor($attributes);
            },
            'user_id' => User::factory(),
            'minutes' => fake()->numberBetween(15, 480),
            'description' => fake()->sentence(),
            'spent_on' => Carbon::today()->subDays(fake()->numberBetween(0, 30))->toDateString(),
            'started_at' => null,
            'ended_at' => null,
            'is_running' => false,
            'is_billable' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TimeEntry $entry): void {
            $entry->workspace_id ??= Project::withoutWorkspaceScope()
                ->whereKey($entry->project_id)
                ->value('workspace_id');
        });
    }

    public function forTask(Task $task): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'task_id' => $task->getKey(),
        ]);
    }

    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * A timer that has not been stopped: minutes are zero until it is.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_running' => true,
            'minutes' => 0,
            'started_at' => now()->subMinutes(25),
            'ended_at' => null,
            'spent_on' => Carbon::today()->toDateString(),
        ]);
    }

    public function onDate(Carbon|string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'spent_on' => Carbon::parse($date)->toDateString(),
        ]);
    }

    public function ofMinutes(int $minutes): static
    {
        return $this->state(fn (array $attributes): array => [
            'minutes' => $minutes,
        ]);
    }

    public function nonBillable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_billable' => false,
        ]);
    }

    /**
     * The task's project when a task was pinned, otherwise a project in the workspace the
     * caller pinned, otherwise a fresh one.
     *
     * @param array<string, mixed> $attributes
     */
    private function projectIdFor(array $attributes): int
    {
        $taskId = $attributes['task_id'] ?? null;

        if ($taskId !== null) {
            $projectId = Task::withoutWorkspaceScope()->whereKey($taskId)->value('project_id');

            if ($projectId !== null) {
                return (int) $projectId;
            }
        }

        $workspaceId = $this->pinnedWorkspaceId($attributes);
        $factory = Project::factory();

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
