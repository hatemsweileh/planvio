<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * @var class-string<User>
     */
    protected $model = User::class;

    /**
     * Hashing is deliberately done once per process: bcrypt is slow by design and a suite
     * that builds hundreds of users would otherwise spend most of its time here.
     */
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => self::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'avatar_path' => null,
            'job_title' => fake()->jobTitle(),
            'timezone' => 'UTC',
            'locale' => 'en',
            'is_admin' => false,
            'is_active' => true,
            'theme' => 'system',
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'last_login_at' => null,
            'last_login_ip' => null,
            'notification_preferences' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A platform super-admin: the only kind of user who may reach the Filament panel.
     */
    public function platformAdmin(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_admin' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'two_factor_secret' => Str::random(32),
            'two_factor_recovery_codes' => array_map(
                static fn (): string => Str::random(10).'-'.Str::random(10),
                range(1, 8),
            ),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
