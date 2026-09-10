<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Workspace>
 */
final class WorkspaceFactory extends Factory
{
    /**
     * @var class-string<Workspace>
     */
    protected $model = Workspace::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(8)),
            'description' => fake()->catchPhrase(),
            'logo_path' => null,
            'accent_color' => '#3F66B0',
            'timezone' => 'UTC',
            'locale' => 'en',
            'currency' => 'USD',
            'date_format' => 'Y-m-d',
            'week_starts_on' => 1,
            'owner_id' => User::factory(),
            'settings' => null,
            'is_suspended' => false,
        ];
    }

    /**
     * The owner is always a member with the owner role — a workspace whose owner cannot
     * act inside it is not a state the product can reach, so the factory does not model it.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Workspace $workspace): void {
            WorkspaceMember::withoutWorkspaceScope()->firstOrCreate(
                [
                    'workspace_id' => $workspace->getKey(),
                    'user_id' => $workspace->owner_id,
                ],
                [
                    'role' => WorkspaceRole::Owner,
                    'joined_at' => now(),
                ],
            );
        });
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_suspended' => true,
        ]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'owner_id' => $user->getKey(),
        ]);
    }
}
