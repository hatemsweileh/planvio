<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TeamMember>
 */
final class TeamMemberFactory extends Factory
{
    /**
     * @var class-string<TeamMember>
     */
    protected $model = TeamMember::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'user_id' => User::factory(),
            'is_lead' => false,
        ];
    }

    public function lead(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_lead' => true,
        ]);
    }
}
