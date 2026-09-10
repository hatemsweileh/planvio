<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Team>
 */
final class TeamFactory extends Factory
{
    /**
     * @var class-string<Team>
     */
    protected $model = Team::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true).' Team';

        return [
            'workspace_id' => Workspace::factory(),
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => fake()->sentence(),
            'color' => fake()->randomElement(['brand', 'blue', 'teal', 'purple', 'amber', 'green']),
        ];
    }
}
