<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TaskChecklistItem>
 */
final class TaskChecklistItemFactory extends Factory
{
    /**
     * @var class-string<TaskChecklistItem>
     */
    protected $model = TaskChecklistItem::class;

    /**
     * Items built by one `count()` call are all instantiated before any of them is stored,
     * so the highest stored position is the same for every one of them. This counter
     * separates them; it lives on the factory instance, which is per terminal `create()`
     * call, so positions restart from whatever the database holds each time.
     */
    private int $positionSequence = 0;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'title' => Str::ucfirst(fake()->words(4, true)),
            'is_done' => false,
            // Appended to whatever the task already has, so a run of items keeps the order
            // it was created in.
            'position' => function (array $attributes): int {
                $highest = (int) TaskChecklistItem::query()
                    ->where('task_id', $attributes['task_id'])
                    ->max('position');

                return $highest + ++$this->positionSequence;
            },
            'completed_at' => null,
            'completed_by' => null,
        ];
    }

    public function done(?User $by = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_done' => true,
            'completed_at' => now(),
            'completed_by' => $by?->getKey(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_done' => false,
            'completed_at' => null,
            'completed_by' => null,
        ]);
    }
}
