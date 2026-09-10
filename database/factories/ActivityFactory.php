<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuthorType;
use App\Models\Activity;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @extends Factory<Activity>
 */
final class ActivityFactory extends Factory
{
    /**
     * @var class-string<Activity>
     */
    protected $model = Activity::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The subject resolves first; `workspace_id` and `project_id` are then taken
            // from it in configure() so the feed row lands in the same tenant and project
            // as the thing it describes.
            'subject_type' => static fn (array $attributes): string => (new Task)->getMorphClass(),
            'subject_id' => function (array $attributes): int {
                return $this->taskIdFor($attributes);
            },
            'causer_id' => User::factory(),
            'causer_type' => AuthorType::User,
            'ai_run_id' => null,
            'event' => 'created',
            'description' => null,
            'properties' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Activity $activity): void {
            $subject = $this->resolveSubject(
                (string) $activity->subject_type,
                $activity->subject_id,
            );

            if ($subject === null) {
                return;
            }

            $activity->workspace_id ??= $subject->getAttribute('workspace_id');
            $activity->project_id ??= $subject->getAttribute('project_id');
        });
    }

    public function about(Model $subject): static
    {
        return $this->state(fn (array $attributes): array => [
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
        ]);
    }

    public function ofEvent(string $event): static
    {
        return $this->state(fn (array $attributes): array => [
            'event' => $event,
        ]);
    }

    /**
     * The shape the change feed renders: which attribute moved, and from what to what.
     */
    public function statusChanged(string $from, string $to): static
    {
        return $this->state(fn (array $attributes): array => [
            'event' => 'status_changed',
            'properties' => [
                'attribute' => 'status_id',
                'old' => $from,
                'new' => $to,
            ],
        ]);
    }

    /**
     * Caused by the agent. `causer_id` stays set: the run acted with a person's authority
     * and the feed says whose.
     */
    public function fromAi(): static
    {
        return $this->state(fn (array $attributes): array => [
            'causer_type' => AuthorType::Ai,
        ]);
    }

    public function causedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'causer_id' => $user->getKey(),
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

    /**
     * Resolve a polymorphic subject through the morph map when one is registered and the
     * class name when it is not.
     */
    private function resolveSubject(string $type, int|string|null $id): ?Model
    {
        if ($id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->withoutGlobalScopes()->find($id);
    }
}
