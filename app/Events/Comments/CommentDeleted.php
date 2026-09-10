<?php

declare(strict_types=1);

namespace App\Events\Comments;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A comment was withdrawn. The row is soft-deleted, so listeners can still read it.
 */
final class CommentDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Comment $comment,
        public readonly User $actor,
    ) {}
}
