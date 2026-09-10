<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Events\Comments\ReactionAdded;
use App\Exceptions\InvalidComment;
use App\Models\Comment;
use App\Models\CommentReaction;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * React to a comment with an emoji.
 *
 * The reaction is stored as the emoji itself in a `varchar(16)`, which is the whole reason
 * this validates: without a check the column is an arbitrary 16-character string that lands
 * in every comment thread. Only emoji sequences are accepted — letters, markup and control
 * characters are refused rather than escaped, because there is no legitimate reaction that
 * needs them.
 *
 * Reacting twice with the same emoji is the same state, so the write is idempotent and the
 * unique index on (comment_id, user_id, emoji) is the backstop.
 */
final class AddReaction
{
    /**
     * Emoji, the modifiers that combine them, keycap digits, and the joiners that hold a
     * sequence like a flag or a family together. Nothing else.
     */
    private const EMOJI_PATTERN = '/^[\p{So}\p{Sk}\p{Mn}\p{Cf}\x{20E3}\x{FE0E}\x{FE0F}\x{200D}0-9#*]+$/u';

    /**
     * The allow-list above admits digits and joiners so keycaps and families survive, which
     * would also let a bare "5" or a lone zero-width character through. A reaction has to
     * carry at least one pictograph — or the enclosing keycap mark that turns a digit into
     * one — to be a reaction at all.
     */
    private const PICTOGRAPH_PATTERN = '/[\p{So}\x{20E3}]/u';

    /** `comment_reactions.emoji` is a varchar(16); MySQL counts that in characters. */
    private const MAX_CHARS = 16;

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(Comment $comment, User $user, string $emoji): CommentReaction
    {
        $emoji = self::normalise($emoji);

        $existing = CommentReaction::query()
            ->where('comment_id', $comment->getKey())
            ->where('user_id', $user->getKey())
            ->where('emoji', $emoji)
            ->first();

        if ($existing instanceof CommentReaction) {
            return $existing;
        }

        $reaction = DB::transaction(function () use ($comment, $user, $emoji): CommentReaction {
            $reaction = CommentReaction::query()->create([
                'comment_id' => $comment->getKey(),
                'user_id' => $user->getKey(),
                'emoji' => $emoji,
            ]);

            // Recorded against the comment rather than the task it sits on: a reaction is
            // not a change to the work, and the project feed should not fill up with them.
            $this->activity->forUser($user)->log($comment, 'reacted', [
                'comment_id' => (int) $comment->getKey(),
                'emoji' => $emoji,
            ]);

            return $reaction;
        });

        $this->events->dispatch(new ReactionAdded($reaction, $comment, $user));

        return $reaction;
    }

    private static function normalise(string $emoji): string
    {
        $emoji = trim($emoji);

        if ($emoji === '' || mb_strlen($emoji, 'UTF-8') > self::MAX_CHARS) {
            throw InvalidComment::invalidReaction($emoji);
        }

        if (preg_match(self::EMOJI_PATTERN, $emoji) !== 1
            || preg_match(self::PICTOGRAPH_PATTERN, $emoji) !== 1) {
            throw InvalidComment::invalidReaction($emoji);
        }

        return $emoji;
    }
}
