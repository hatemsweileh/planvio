<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\MilestoneStatus;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<Milestone>
 */
final class MilestoneFactory extends Factory
{
    /**
     * @var class-string<Milestone>
     */
    protected $model = Milestone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The due date is measured from today rather than from the start date, so the
        // default milestone is never accidentally overdue. overdue() states that on purpose.
        $start = Carbon::today()->subDays(fake()->numberBetween(0, 30));
        $due = Carbon::today()->addDays(fake()->numberBetween(14, 90));

        return [
            // `project_id` is resolved first so the workspace can be taken from the
            // project. That keeps `->for($project)` from producing a milestone whose two
            // tenancy columns point at different workspaces.
            'project_id' => Project::factory(),
            'workspace_id' => static fn (array $attributes): int => (int) Project::withoutWorkspaceScope()
                ->whereKey($attributes['project_id'])
                ->value('workspace_id'),
            'name' => Str::title(fake()->words(3, true)),
            'description' => fake()->sentence(),
            'status' => MilestoneStatus::Planned,
            'start_date' => $start,
            'due_date' => $due,
            'completed_at' => null,
            'owner_id' => null,
            'position' => fake()->numberBetween(0, 10),
            'progress' => 0,
        ];
    }

    public function withStatus(MilestoneStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
        ]);
    }

    public function inProgress(): static
    {
        return $this->withStatus(MilestoneStatus::InProgress);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MilestoneStatus::Completed,
            'completed_at' => now(),
            'progress' => 100,
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MilestoneStatus::InProgress,
            'start_date' => Carbon::today()->subDays(60),
            'due_date' => Carbon::today()->subDays(5),
            'completed_at' => null,
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => MilestoneStatus::Planned,
            'start_date' => Carbon::today(),
            'due_date' => Carbon::today()->addDays(14),
            'completed_at' => null,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_id' => $user->getKey(),
        ]);
    }
}
