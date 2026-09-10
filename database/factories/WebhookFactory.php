<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Webhook>
 */
final class WebhookFactory extends Factory
{
    protected $model = Webhook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => ucfirst(fake()->words(2, true)),
            'url' => 'https://'.fake()->unique()->domainName().'/hooks/planvio',
            'secret' => Str::random(40),
            'events' => ['task.created', 'task.updated'],
            'is_active' => true,
            'last_delivered_at' => null,
            'failure_count' => 0,
        ];
    }

    /**
     * @param array<int, string> $events
     */
    public function forEvents(array $events): self
    {
        return $this->state(fn (): array => ['events' => $events]);
    }

    public function catchAll(): self
    {
        return $this->state(fn (): array => ['events' => [Webhook::EVENT_WILDCARD]]);
    }

    public function inactive(): self
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function failing(int $failures = 3): self
    {
        return $this->state(fn (): array => ['failure_count' => $failures]);
    }
}
