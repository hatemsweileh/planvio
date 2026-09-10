<?php

declare(strict_types=1);

namespace App\Events\Comments;

use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Somebody reacted to a comment.
 */
final class ReactionAdded implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly CommentReaction $reaction,
        public readonly Comment $comment,
        public readonly User $actor,
    ) {}
}
