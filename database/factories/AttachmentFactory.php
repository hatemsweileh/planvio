<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attachment>
 */
final class AttachmentFactory extends Factory
{
    /**
     * @var class-string<Attachment>
     */
    protected $model = Attachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::slug(fake()->words(3, true));

        return [
            // The subject resolves first so `workspace_id` can be taken from it in
            // configure().
            'attachable_type' => static fn (array $attributes): string => (new Task)->getMorphClass(),
            'attachable_id' => function (array $attributes): int {
                return $this->taskIdFor($attributes);
            },
            'uploaded_by' => User::factory(),
            // Never the public disk: attachments are streamed through the authorising
            // download route (ARCHITECTURE.md §9).
            'disk' => 'private',
            'path' => 'attachments/'.fake()->uuid().'/'.$name.'.pdf',
            'original_name' => $name.'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => fake()->numberBetween(1024, 5_242_880),
            'checksum' => hash('sha256', (string) fake()->unique()->uuid()),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Attachment $attachment): void {
            $attachment->workspace_id ??= $this->workspaceIdOf(
                (string) $attachment->attachable_type,
                $attachment->attachable_id,
            );
        });
    }

    public function on(Model $attachable): static
    {
        return $this->state(fn (array $attributes): array => [
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
        ]);
    }

    public function image(): static
    {
        return $this->state(function (array $attributes): array {
            $name = Str::slug(fake()->words(3, true));

            return [
                'path' => 'attachments/'.fake()->uuid().'/'.$name.'.png',
                'original_name' => $name.'.png',
                'mime' => 'image/png',
                'extension' => 'png',
            ];
        });
    }

    public function uploadedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'uploaded_by' => $user->getKey(),
        ]);
    }

    public function ofSize(int $bytes): static
    {
        return $this->state(fn (array $attributes): array => [
            'size_bytes' => $bytes,
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
