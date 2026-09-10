<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<TaskStatus>
 */
final class TaskStatusFactory extends Factory
{
    /**
     * @var class-string<TaskStatus>
     */
    protected $model = TaskStatus::class;

    /**
     * Statuses built by one `count()` call are all instantiated before any of them is
     * stored, so the highest stored position is the same for every one of them. This
     * counter separates them; it lives on the factory instance, which is per terminal
     * `create()` call, so positions restart from whatever the database holds each time.
     */
    private int $positionSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `project_id` resolves first so `workspace_id` can be taken from the project
            // (see configure()). A status whose two tenancy columns disagree is not a
            // state the product can reach, so the factory does not produce one.
            'project_id' => function (array $attributes): int {
                return $this->projectIdFor($attributes);
            },
            'name' => 'To Do',
            'color' => 'blue',
            'category' => StatusCategory::Todo,
            'position' => function (array $attributes): int {
                $highest = (int) TaskStatus::withoutWorkspaceScope()
                    ->where('project_id', $attributes['project_id'])
                    ->max('position');

                return $highest + ++$this->positionSequence;
            },
            'is_default' => false,
            'is_completed' => false,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (TaskStatus $status): void {
            $status->workspace_id ??= Project::withoutWorkspaceScope()
                ->whereKey($status->project_id)
                ->value('workspace_id');
        });
    }

    public function inCategory(StatusCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => ucwords(str_replace('_', ' ', $category->value)),
            'color' => $category->color(),
            'category' => $category,
            'is_completed' => $category->isClosed(),
        ]);
    }

    public function asDefault(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }

    /**
     * A status that closes the tasks sitting in it.
     */
    public function completed(): static
    {
        return $this->inCategory(StatusCategory::Done);
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
