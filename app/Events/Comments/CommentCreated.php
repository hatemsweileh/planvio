<?php

declare(strict_types=1);

namespace App\Events\Comments;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * A comment was posted on something.
 *
 * The users already notified travel with the event so a listener that fans out further
 * does not notify them twice.
 */
final class CommentCreated implements ShouldDispatchAfterCommit
{
    /**
     * @param list<int> $mentionedUserIds members resolved from @-mentions in the body
     * @param list<int> $notifiedUserIds everyone the action already notified
     */
    public function __construct(
        public readonly Comment $comment,
        public readonly Model $commentable,
        public readonly ?User $actor,
        public readonly array $mentionedUserIds = [],
        public readonly array $notifiedUserIds = [],
    ) {}
}
