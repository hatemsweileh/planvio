<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StatusCategory;
use App\Models\ProjectStatus;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectStatus>
 */
final class ProjectStatusFactory extends Factory
{
    /**
     * @var class-string<ProjectStatus>
     */
    protected $model = ProjectStatus::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = fake()->randomElement([
            StatusCategory::Todo,
            StatusCategory::InProgress,
            StatusCategory::Blocked,
            StatusCategory::Done,
        ]);

        return [
            'workspace_id' => Workspace::factory(),
            'name' => Str::headline($category->value),
            'color' => $category->color(),
            'category' => $category,
            'position' => fake()->numberBetween(0, 10),
            'is_default' => false,
        ];
    }

    public function inCategory(StatusCategory $category): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => Str::headline($category->value),
            'color' => $category->color(),
            'category' => $category,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
            'position' => 0,
        ]);
    }
}
