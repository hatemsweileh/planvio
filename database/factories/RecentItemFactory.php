<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\RecentItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RecentItem>
 */
final class RecentItemFactory extends Factory
{
    protected $model = RecentItem::class;

    /**
     * The default viewable is created inside the same workspace as the row itself — a recent
     * item pointing at another tenant's record would be nonsense to assert against.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => Workspace::factory(),
            'viewable_type' => Project::class,
            'viewable_id' => fn (array $attributes): int => (int) Project::factory()
                ->create(['workspace_id' => $attributes['workspace_id']])
                ->getKey(),
            'viewed_at' => Carbon::now(),
        ];
    }

    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    public function of(Model $viewable): self
    {
        return $this->state(fn (): array => [
            'viewable_type' => $viewable->getMorphClass(),
            'viewable_id' => $viewable->getKey(),
        ]);
    }

    public function viewedAt(Carbon $moment): self
    {
        return $this->state(fn (): array => ['viewed_at' => $moment]);
    }
}
