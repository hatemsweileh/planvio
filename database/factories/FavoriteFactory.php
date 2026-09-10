<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Favorite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Favorite>
 */
final class FavoriteFactory extends Factory
{
    protected $model = Favorite::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'favoritable_type' => Project::class,
            'favoritable_id' => Project::factory(),
            'position' => 0,
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function of(Model $favoritable): self
    {
        return $this->state(fn (): array => [
            'favoritable_type' => $favoritable->getMorphClass(),
            'favoritable_id' => $favoritable->getKey(),
        ]);
    }

    public function atPosition(int $position): self
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
