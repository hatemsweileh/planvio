<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceMember>
 */
final class WorkspaceMemberFactory extends Factory
{
    /**
     * @var class-string<WorkspaceMember>
     */
    protected $model = WorkspaceMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'role' => WorkspaceRole::Member,
            'title' => fake()->jobTitle(),
            'joined_at' => now(),
            'last_active_at' => null,
        ];
    }

    public function withRole(WorkspaceRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }

    public function owner(): static
    {
        return $this->withRole(WorkspaceRole::Owner);
    }

    public function admin(): static
    {
        return $this->withRole(WorkspaceRole::Admin);
    }

    public function manager(): static
    {
        return $this->withRole(WorkspaceRole::Manager);
    }

    public function guest(): static
    {
        return $this->withRole(WorkspaceRole::Guest);
    }
}
