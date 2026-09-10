<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMode;
use App\Enums\AiScope;
use App\Models\AiConversation;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AiConversation>
 */
final class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'task_id' => null,
            'user_id' => User::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'mode' => AiMode::Assistant,
            'scope' => AiScope::Workspace,
            'last_activity_at' => Carbon::now(),
            'message_count' => 0,
            'is_archived' => false,
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'scope' => AiScope::Project,
        ]);
    }

    public function forTask(Task $task): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $task->workspace_id,
            'project_id' => $task->project_id,
            'task_id' => $task->getKey(),
            'scope' => AiScope::Task,
        ]);
    }

    public function inMode(AiMode $mode): self
    {
        return $this->state(fn (): array => ['mode' => $mode]);
    }

    public function archived(): self
    {
        return $this->state(fn (): array => ['is_archived' => true]);
    }

    public function lastActiveAt(Carbon $moment): self
    {
        return $this->state(fn (): array => ['last_activity_at' => $moment]);
    }
}
