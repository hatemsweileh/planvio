<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Events\Comments\CommentDeleted;
use App\Models\Comment;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Withdraw a comment.
 *
 * A soft delete, so the discussion keeps its shape: replies and reactions stay attached and
 * an accidental deletion is recoverable. Attachments on the comment are left alone for the
 * same reason — removing their bytes here would make the restore incomplete.
 */
final class DeleteComment
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(Comment $comment, User $actor): Comment
    {
        // Deleting twice is not an error; it is the same outcome reached again.
        if ($comment->trashed()) {
            return $comment;
        }

        $commentable = $comment->commentable;

        DB::transaction(function () use ($comment, $actor, $commentable): void {
            $comment->delete();

            $this->activity->forUser($actor)->log($commentable ?? $comment, 'comment_deleted', [
                'comment_id' => (int) $comment->getKey(),
                'author_id' => $comment->user_id === null ? null : (int) $comment->user_id,
                'reply_count' => $comment->replies()->count(),
            ]);
        });

        $this->events->dispatch(new CommentDeleted($comment, $actor));

        return $comment;
    }
}
