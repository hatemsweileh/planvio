<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiDriver;
use App\Models\AiProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiProvider>
 */
final class AiProviderFactory extends Factory
{
    protected $model = AiProvider::class;

    /**
     * Inactive by default: a test that wants the AI layer to run must say so, so nothing is
     * ever reachable by accident.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Provider '.fake()->unique()->numberBetween(1, 999999),
            'driver' => AiDriver::OpenAi,
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-'.Str::random(32),
            'model' => 'gpt-4o-mini',
            'fallback_model' => null,
            'temperature' => 0.2,
            'max_tokens' => 2048,
            'timeout_seconds' => 60,
            'headers' => null,
            'options' => null,
            'is_active' => false,
            'is_default' => false,
        ];
    }

    public function active(): self
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }

    public function default(): self
    {
        return $this->state(fn (): array => [
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function anthropic(): self
    {
        return $this->state(fn (): array => [
            'driver' => AiDriver::Anthropic,
            'base_url' => 'https://api.anthropic.com/v1',
            'model' => 'claude-sonnet-4-5',
        ]);
    }

    public function compatible(string $baseUrl, string $model = 'local-model'): self
    {
        return $this->state(fn (): array => [
            'driver' => AiDriver::OpenAiCompatible,
            'base_url' => $baseUrl,
            'model' => $model,
        ]);
    }

    public function withDriver(AiDriver $driver): self
    {
        return $this->state(fn (): array => ['driver' => $driver]);
    }

    public function withoutApiKey(): self
    {
        return $this->state(fn (): array => ['api_key' => null]);
    }
}
