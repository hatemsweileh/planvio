<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tag>
 */
final class TagFactory extends Factory
{
    /**
     * @var class-string<Tag>
     */
    protected $model = Tag::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'workspace_id' => Workspace::factory(),
            'name' => $name,
            // Globally unique, so unique(workspace_id, slug) still holds when several tags
            // are built for the same workspace.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'color' => fake()->randomElement(['red', 'orange', 'amber', 'green', 'teal', 'blue', 'purple', 'pink', 'gray']),
            'description' => fake()->sentence(),
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
        ]);
    }

    public function colored(string $color): static
    {
        return $this->state(fn (array $attributes): array => [
            'color' => $color,
        ]);
    }
}
