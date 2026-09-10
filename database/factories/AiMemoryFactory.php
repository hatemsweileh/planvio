<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiMemoryScope;
use App\Enums\AiMemorySource;
use App\Models\AiMemory;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AiMemory>
 */
final class AiMemoryFactory extends Factory
{
    protected $model = AiMemory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'project_id' => null,
            'user_id' => null,
            'scope' => AiMemoryScope::Workspace,
            // unique(workspace_id, project_id, user_id, scope, key) — a run-unique suffix
            // keeps several memories on one workspace from colliding.
            'key' => 'memory_'.fake()->unique()->numberBetween(1, 999999),
            'content' => fake()->sentence(),
            'importance' => 1,
            'expires_at' => null,
            'source' => AiMemorySource::Ai,
        ];
    }

    public function forProject(Project $project): self
    {
        return $this->state(fn (): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'scope' => AiMemoryScope::Project,
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => [
            'user_id' => $user->getKey(),
            'scope' => AiMemoryScope::User,
        ]);
    }

    public function withKey(string $key, ?string $content = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'key' => $key,
            'content' => $content ?? $attributes['content'],
        ]);
    }

    public function important(int $importance = 5): self
    {
        return $this->state(fn (): array => ['importance' => $importance]);
    }

    public function fromSource(AiMemorySource $source): self
    {
        return $this->state(fn (): array => ['source' => $source]);
    }

    public function expiring(Carbon $at): self
    {
        return $this->state(fn (): array => ['expires_at' => $at]);
    }

    public function expired(): self
    {
        return $this->state(fn (): array => ['expires_at' => Carbon::now()->subDay()]);
    }
}
