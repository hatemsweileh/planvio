<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Expense>
 */
final class ExpenseFactory extends Factory
{
    /**
     * @var class-string<Expense>
     */
    protected $model = Expense::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // `project_id` resolves first so `workspace_id` can be taken from the project
            // in configure().
            'project_id' => function (array $attributes): int {
                return $this->projectIdFor($attributes);
            },
            'user_id' => User::factory(),
            'amount' => fake()->randomFloat(2, 25, 5000),
            'currency' => 'USD',
            'category' => fake()->randomElement(['travel', 'software', 'hardware', 'contractor', 'hosting', 'other']),
            'description' => fake()->sentence(),
            'incurred_on' => Carbon::today()->subDays(fake()->numberBetween(0, 90))->toDateString(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Expense $expense): void {
            $expense->workspace_id ??= Project::withoutWorkspaceScope()
                ->whereKey($expense->project_id)
                ->value('workspace_id');
        });
    }

    public function ofAmount(float|string $amount, string $currency = 'USD'): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    public function inCategory(string $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'category' => $category,
        ]);
    }

    public function onDate(Carbon|string $date): static
    {
        return $this->state(fn (array $attributes): array => [
            'incurred_on' => Carbon::parse($date)->toDateString(),
        ]);
    }

    public function paidBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
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
