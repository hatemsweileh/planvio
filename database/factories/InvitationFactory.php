<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\Invitation;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
final class InvitationFactory extends Factory
{
    /**
     * @var class-string<Invitation>
     */
    protected $model = Invitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => WorkspaceRole::Member,
            'token' => Str::random(64),
            'invited_by' => User::factory(),
            'project_id' => null,
            'expires_at' => now()->addDays(7),
            'accepted_at' => null,
        ];
    }

    public function withRole(WorkspaceRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
        ]);
    }

    /**
     * A project-scoped guest invitation. The workspace is taken from the project so the
     * two columns cannot disagree.
     */
    public function forProject(Project $project, WorkspaceRole $role = WorkspaceRole::Guest): static
    {
        return $this->state(fn (array $attributes): array => [
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'role' => $role,
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'expires_at' => now()->subDay(),
            'accepted_at' => null,
        ]);
    }
}
