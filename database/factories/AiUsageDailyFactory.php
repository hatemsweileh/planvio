<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiProvider;
use App\Models\AiUsageDaily;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<AiUsageDaily>
 */
final class AiUsageDailyFactory extends Factory
{
    protected $model = AiUsageDaily::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $runs = fake()->numberBetween(1, 40);

        return [
            'date' => Carbon::now()->toDateString(),
            'workspace_id' => Workspace::factory(),
            'user_id' => null,
            'ai_provider_id' => null,
            'model' => 'gpt-4o-mini',
            'runs' => $runs,
            'tool_calls' => $runs * fake()->numberBetween(1, 6),
            'tokens_in' => $runs * fake()->numberBetween(300, 2000),
            'tokens_out' => $runs * fake()->numberBetween(100, 900),
            'errors' => 0,
        ];
    }

    public function onDate(Carbon|string $date): self
    {
        return $this->state(fn (): array => [
            'date' => $date instanceof Carbon ? $date->toDateString() : $date,
        ]);
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function forProvider(AiProvider $provider): self
    {
        return $this->state(fn (): array => [
            'ai_provider_id' => $provider->getKey(),
            'model' => $provider->model,
        ]);
    }

    /**
     * A bucket that belongs to no tenant.
     */
    public function platform(): self
    {
        return $this->state(fn (): array => ['workspace_id' => null]);
    }

    public function withErrors(int $errors = 3): self
    {
        return $this->state(fn (): array => ['errors' => $errors]);
    }
}
