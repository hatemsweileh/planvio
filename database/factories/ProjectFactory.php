<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
final class ProjectFactory extends Factory
{
    /**
     * @var class-string<Project>
     */
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->words(3, true));
        // Measured from today, not from the start date, so a default project is never
        // accidentally overdue. overdue() states that on purpose.
        $start = Carbon::today()->subDays(fake()->numberBetween(0, 60));
        $target = Carbon::today()->addDays(fake()->numberBetween(30, 180));

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            // Globally unique, so it also satisfies unique(workspace_id, key) when several
            // projects are built for the same workspace.
            'key' => mb_strtoupper(fake()->unique()->bothify('???##')),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => fake()->paragraph(),
            'icon' => null,
            'logo_path' => null,
            'color' => '#3F66B0',
            'type' => ProjectType::General,
            'status_id' => null,
            'health' => ProjectHealth::OnTrack,
            'health_note' => null,
            'health_set_manually' => false,
            'priority' => Priority::Medium,
            'owner_id' => User::factory(),
            'manager_id' => null,
            'client_name' => null,
            'department' => null,
            'start_date' => $start,
            'target_date' => $target,
            'completed_at' => null,
            'budget' => fake()->randomFloat(2, 5000, 250000),
            'currency' => 'USD',
            'progress' => 0,
            'settings' => null,
            'ai_settings' => null,
            'is_archived' => false,
            'archived_at' => null,
        ];
    }

    public function ofType(ProjectType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_id' => $user->getKey(),
        ]);
    }

    public function managedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'manager_id' => $user->getKey(),
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_archived' => true,
            'archived_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed_at' => now(),
            'progress' => 100,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'start_date' => Carbon::today()->subDays(120),
            'target_date' => Carbon::today()->subDays(7),
            'completed_at' => null,
            'is_archived' => false,
        ]);
    }

    public function atRisk(): static
    {
        return $this->state(fn (array $attributes): array => [
            'health' => ProjectHealth::AtRisk,
            'health_note' => fake()->sentence(),
            'health_set_manually' => true,
        ]);
    }

    /**
     * Attach a workspace status. Done after creation because the project's workspace is
     * only known once the parent factories have resolved.
     */
    public function withStatus(): static
    {
        return $this->afterCreating(function (Project $project): void {
            $status = ProjectStatus::factory()->create([
                'workspace_id' => $project->workspace_id,
            ]);

            $project->forceFill(['status_id' => $status->getKey()])->save();
        });
    }
}
