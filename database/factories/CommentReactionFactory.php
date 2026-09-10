<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CommentReaction>
 */
final class CommentReactionFactory extends Factory
{
    /**
     * @var class-string<CommentReaction>
     */
    protected $model = CommentReaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'comment_id' => Comment::factory(),
            'user_id' => User::factory(),
            'emoji' => fake()->randomElement(['👍', '🎉', '👀', '❤️', '🚀', '😄']),
        ];
    }

    public function withEmoji(string $emoji): static
    {
        return $this->state(fn (array $attributes): array => [
            'emoji' => $emoji,
        ]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $user->getKey(),
        ]);
    }
}
