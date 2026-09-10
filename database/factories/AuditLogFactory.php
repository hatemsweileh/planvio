<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * Platform-level by default: most audited events (sign-in, settings) belong to no tenant.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'workspace_id' => null,
            'event' => 'auth.login',
            'description' => fake()->sentence(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'properties' => ['source' => 'web'],
        ];
    }

    public function forEvent(string $event): self
    {
        return $this->state(fn (): array => ['event' => $event]);
    }

    public function inWorkspace(Workspace $workspace): self
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->getKey()]);
    }

    public function byUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    /**
     * An event with no actor — the scheduler, the installer, or a deleted account.
     */
    public function system(): self
    {
        return $this->state(fn (): array => [
            'user_id' => null,
            'event' => 'system.maintenance',
            'ip' => null,
            'user_agent' => null,
        ]);
    }
}
