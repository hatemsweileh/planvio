<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
final class SettingFactory extends Factory
{
    protected $model = Setting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'test.'.fake()->unique()->numberBetween(1, 999999),
            'value' => fake()->sentence(),
            'is_encrypted' => false,
        ];
    }

    public function withKey(string $key, mixed $value = null): self
    {
        return $this->state(fn (array $attributes): array => [
            'key' => $key,
            'value' => $value ?? $attributes['value'],
        ]);
    }

    /**
     * Marks the row as holding ciphertext. The plaintext-to-ciphertext step belongs to
     * App\Support\Settings, so a factory-made row carries the flag only.
     */
    public function encrypted(): self
    {
        return $this->state(fn (): array => ['is_encrypted' => true]);
    }
}
