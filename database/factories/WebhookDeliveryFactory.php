<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<WebhookDelivery>
 */
final class WebhookDeliveryFactory extends Factory
{
    protected $model = WebhookDelivery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_id' => Webhook::factory(),
            'event' => 'task.created',
            'payload' => [
                'event' => 'task.created',
                'data' => ['id' => fake()->numberBetween(1, 9999), 'title' => fake()->sentence(3)],
            ],
            'response_status' => 200,
            'response_body' => '{"ok":true}',
            'attempt' => 1,
            'delivered_at' => Carbon::now(),
        ];
    }

    public function forWebhook(Webhook $webhook): self
    {
        return $this->state(fn (): array => [
            'webhook_id' => $webhook->getKey(),
        ]);
    }

    public function failed(int $status = 500): self
    {
        return $this->state(fn (): array => [
            'response_status' => $status,
            'response_body' => 'Internal Server Error',
        ]);
    }

    /**
     * The endpoint was never reached, so no status came back.
     */
    public function unreachable(): self
    {
        return $this->state(fn (): array => [
            'response_status' => null,
            'response_body' => null,
            'delivered_at' => null,
        ]);
    }
}
