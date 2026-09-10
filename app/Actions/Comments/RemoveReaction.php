<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Events\Comments\ReactionRemoved;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Take back one of your own reactions.
 *
 * Returns the comment: the reaction row is gone, so it is the comment whose state changed.
 * Removing a reaction that is not there is a no-op, not a failure — the caller asked for a
 * state that already holds.
 */
final class RemoveReaction
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(Comment $comment, User $user, string $emoji): Comment
    {
        $emoji = trim($emoji);

        $reaction = CommentReaction::query()
            ->where('comment_id', $comment->getKey())
            ->where('user_id', $user->getKey())
            ->where('emoji', $emoji)
            ->first();

        if (! $reaction instanceof CommentReaction) {
            return $comment;
        }

        DB::transaction(function () use ($comment, $user, $emoji, $reaction): void {
            $reaction->delete();

            $this->activity->forUser($user)->log($comment, 'unreacted', [
                'comment_id' => (int) $comment->getKey(),
                'emoji' => $emoji,
            ]);
        });

        $this->events->dispatch(new ReactionRemoved($comment, $user, $emoji));

        return $comment;
    }
}
