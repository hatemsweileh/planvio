<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Task;
use App\Models\TaskWatcher;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskWatcher>
 */
final class TaskWatcherFactory extends Factory
{
    /**
     * @var class-string<TaskWatcher>
     */
    protected $model = TaskWatcher::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
        ];
    }

    public function watching(Task $task, User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'task_id' => $task->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }
}
