<?php

declare(strict_types=1);

namespace App\Events\Comments;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A comment's body was edited.
 */
final class CommentUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param list<int> $newlyMentionedUserIds members mentioned by the edit but not before
     */
    public function __construct(
        public readonly Comment $comment,
        public readonly User $actor,
        public readonly array $newlyMentionedUserIds = [],
    ) {}
}
