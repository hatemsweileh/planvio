<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Priority;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Task>
 */
final class TaskFactory extends Factory
{
    /**
     * @var class-string<Task>
     */
    protected $model = Task::class;

    /**
     * Tasks built by one `count()` call are all instantiated before any of them is stored,
     * so the highest number in the database is the same for every one of them. This
     * counter separates them; it lives on the factory instance, which is per terminal
     * `create()` call, so numbering restarts from whatever the database holds each time.
     */
    private int $numberSequence = 0;

    /**
     * The attribute order matters. `project_id` is resolved first because the status, the
     * per-project task number and the workspace column all derive from it, and Eloquent
     * expands factory attributes in the order the definition declares them.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => function (array $attributes): int {
                return $this->projectIdFor($attributes);
            },
            'number' => function (array $attributes): int {
                $highest = (int) Task::withoutWorkspaceScope()
                    ->withTrashed()
                    ->where('project_id', $attributes['project_id'])
                    ->max('number');

                return $highest + ++$this->numberSequence;
            },
            'status_id' => static function (array $attributes): int {
                $projectId = (int) $attributes['project_id'];

                // Reuse the board the project already has — a task landing in a column
                // nobody else uses would make every board assertion vacuous.
                $existing = TaskStatus::withoutWorkspaceScope()
                    ->where('project_id', $projectId)
                    ->where('is_completed', false)
                    ->orderByDesc('is_default')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->value('id');

                return $existing !== null
                    ? (int) $existing
                    : (int) TaskStatus::factory()->asDefault()->create(['project_id' => $projectId])->getKey();
            },
            'title' => Str::ucfirst(fake()->words(5, true)),
            'description' => fake()->paragraph(),
            'priority' => Priority::Medium,
            'assignee_id' => null,
            'reporter_id' => User::factory(),
            'parent_id' => null,
            'milestone_id' => null,
            'start_date' => null,
            'due_date' => null,
            'completed_at' => null,
            'estimate_minutes' => fake()->randomElement([null, 60, 120, 240, 480]),
            // Fractional ordering: leaving a gap between neighbours means a drag-and-drop
            // reorder can slot a task between two others without rewriting the column.
            'position' => static fn (array $attributes): float => (float) $attributes['number'] * 1000,
            'progress' => 0,
            'recurring_task_id' => null,
            'created_by' => static fn (array $attributes): int => (int) $attributes['reporter_id'],
            'ai_generated' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Task $task): void {
            $task->workspace_id ??= Project::withoutWorkspaceScope()
                ->whereKey($task->project_id)
                ->value('workspace_id');
        });
    }

    public function withPriority(Priority $priority): static
    {
        return $this->state(fn (array $attributes): array => [
            'priority' => $priority,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'assignee_id' => $user->getKey(),
        ]);
    }

    public function unassigned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'assignee_id' => null,
        ]);
    }

    public function dueOn(Carbon|string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_date' => Carbon::parse($date)->toDateString(),
        ]);
    }

    /**
     * Past its due date and still open.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_date' => Carbon::today()->subDays(30)->toDateString(),
            'due_date' => Carbon::today()->subDays(7)->toDateString(),
            'completed_at' => null,
        ]);
    }

    /**
     * Closed, and sitting in a status that says so: the `completed_at` column and the
     * status category are read by different call sites and must agree.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed_at' => now(),
            'progress' => 100,
        ])->afterCreating(function (Task $task): void {
            $status = TaskStatus::withoutWorkspaceScope()
                ->where('project_id', $task->project_id)
                ->where('is_completed', true)
                ->orderBy('position')
                ->orderBy('id')
                ->first()
                ?? TaskStatus::factory()->completed()->create(['project_id' => $task->project_id]);

            if ((int) $task->status_id !== (int) $status->getKey()) {
                $task->forceFill(['status_id' => $status->getKey()])->save();
            }
        });
    }

    public function subtaskOf(Task $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'parent_id' => $parent->getKey(),
            'project_id' => $parent->project_id,
            'workspace_id' => $parent->workspace_id,
        ]);
    }

    public function forMilestone(Milestone $milestone): static
    {
        return $this->state(fn (array $attributes): array => [
            'milestone_id' => $milestone->getKey(),
            'project_id' => $milestone->project_id,
            'workspace_id' => $milestone->workspace_id,
        ]);
    }

    public function aiGenerated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ai_generated' => true,
        ]);
    }

    /**
     * A project in the workspace the caller pinned, or a fresh one when they pinned none.
     *
     * @param array<string, mixed> $attributes
     */
    private function projectIdFor(array $attributes): int
    {
        $workspaceId = $this->pinnedWorkspaceId($attributes);
        $factory = Project::factory();

        if ($workspaceId !== null) {
            $factory = $factory->state(['workspace_id' => $workspaceId]);
        }

        return (int) $factory->create()->getKey();
    }

    /**
     * The workspace the caller supplied, whether through `for()`, a state or create
     * attributes. Null means "derive it from the parent record".
     *
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
