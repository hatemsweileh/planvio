<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuthorType;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @extends Factory<Comment>
 */
final class CommentFactory extends Factory
{
    /**
     * @var class-string<Comment>
     */
    protected $model = Comment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // The subject resolves first so `workspace_id` can be taken from it in
            // configure(); a comment filed under a different tenant than the thing it is
            // attached to would be invisible to exactly the queries that should find it.
            'commentable_type' => static fn (array $attributes): string => (new Task)->getMorphClass(),
            'commentable_id' => function (array $attributes): int {
                return $this->taskIdFor($attributes);
            },
            'user_id' => User::factory(),
            'body' => '<p>'.e(fake()->paragraph()).'</p>',
            'author_type' => AuthorType::User,
            'ai_run_id' => null,
            'parent_id' => null,
            'edited_at' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Comment $comment): void {
            $comment->workspace_id ??= $this->workspaceIdOf(
                (string) $comment->commentable_type,
                $comment->commentable_id,
            );
        });
    }

    public function on(Model $commentable): static
    {
        return $this->state(fn (array $attributes): array => [
            'commentable_type' => $commentable->getMorphClass(),
            'commentable_id' => $commentable->getKey(),
        ]);
    }

    /**
     * Written by the agent rather than a person. `user_id` is null because no person
     * authored it — the acting user of the run is recorded on the run itself.
     */
    public function fromAi(): static
    {
        return $this->state(fn (array $attributes): array => [
            'author_type' => AuthorType::Ai,
            'user_id' => null,
        ]);
    }

    public function replyTo(Comment $parent): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_id' => $parent->workspace_id,
            'commentable_type' => $parent->commentable_type,
            'commentable_id' => $parent->commentable_id,
            'parent_id' => $parent->getKey(),
        ]);
    }

    public function edited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'edited_at' => now(),
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
     * The tenant of a polymorphic parent, resolved through the morph map when one is
     * registered and the class name when it is not.
     */
    private function workspaceIdOf(string $type, int|string|null $id): ?int
    {
        if ($id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $related = $class::query()->withoutGlobalScopes()->find($id);
        $workspaceId = $related?->getAttribute('workspace_id');

        return $workspaceId === null ? null : (int) $workspaceId;
    }
}
