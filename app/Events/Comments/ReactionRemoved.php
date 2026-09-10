<?php

declare(strict_types=1);

namespace App\Events\Comments;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A reaction was taken back. The row is gone, so the emoji travels with the event.
 */
final class ReactionRemoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Comment $comment,
        public readonly User $actor,
        public readonly string $emoji,
    ) {}
}
