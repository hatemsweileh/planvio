<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Priority;
use App\Enums\RecurrenceFrequency;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecurringTask>
 */
final class RecurringTaskFactory extends Factory
{
    /**
     * @var class-string<RecurringTask>
     */
    protected $model = RecurringTask::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = Carbon::today();

        return [
            'project_id' => function (array $attributes): int {
                return $this->projectIdFor($attributes);
            },
            'template' => [
                'title' => Str::ucfirst(fake()->words(4, true)),
                'description' => fake()->sentence(),
                'priority' => Priority::Medium->value,
                'assignee_id' => null,
                'estimate_minutes' => 60,
            ],
            'frequency' => RecurrenceFrequency::Weekly,
            'interval' => 1,
            'by_weekday' => [Carbon::MONDAY],
            'by_monthday' => null,
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => null,
            'next_run_on' => $startsOn->toDateString(),
            'last_run_on' => null,
            'occurrences_generated' => 0,
            'max_occurrences' => null,
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (RecurringTask $recurringTask): void {
            $recurringTask->workspace_id ??= Project::withoutWorkspaceScope()
                ->whereKey($recurringTask->project_id)
                ->value('workspace_id');
        });
    }

    public function withFrequency(RecurrenceFrequency $frequency, int $interval = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => $frequency,
            'interval' => $interval,
        ]);
    }

    public function daily(): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => RecurrenceFrequency::Daily,
            'interval' => 1,
            'by_weekday' => null,
        ]);
    }

    public function monthly(int $dayOfMonth = 1): static
    {
        return $this->state(fn (array $attributes): array => [
            'frequency' => RecurrenceFrequency::Monthly,
            'interval' => 1,
            'by_weekday' => null,
            'by_monthday' => [$dayOfMonth],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * A rule whose cursor has already come round, ready for the generator to pick up.
     */
    public function due(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => true,
            'starts_on' => Carbon::today()->subMonth()->toDateString(),
            'next_run_on' => Carbon::today()->toDateString(),
        ]);
    }

    public function exhausted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'max_occurrences' => 5,
            'occurrences_generated' => 5,
            'last_run_on' => Carbon::today()->subDay()->toDateString(),
            'next_run_on' => null,
            'is_active' => false,
        ]);
    }

    /**
     * A project in the workspace the caller pinned, or a fresh one when they pinned none.
     *
     * @param array<string, mixed> $attributes
     */
    private function projectIdFor(array $attributes): int
    {
        $workspaceId = $this->pinnedWorkspaceId($attributes);
        $factory = Project::factory();

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
}
