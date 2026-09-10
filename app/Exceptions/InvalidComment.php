<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Comment;
use Illuminate\Database\Eloquent\Model;

/**
 * A comment cannot be written as asked.
 *
 * Sanitisation runs before this check, so an "empty" comment is one whose entire content
 * was markup the sanitiser refused: the author sees a refusal rather than a blank row that
 * silently swallowed their payload.
 */
final class InvalidComment extends DomainException
{
    public static function empty(): self
    {
        return new self(__('actions.comments.empty_body'));
    }

    /**
     * The subject carries no tenant column, so a comment on it could not be scoped to a
     * workspace and would be visible to everyone or to no one.
     */
    public static function notCommentable(Model $commentable): self
    {
        return new self(
            __('actions.comments.not_commentable'),
            [
                'commentable_type' => $commentable->getMorphClass(),
                'commentable_id' => $commentable->getKey() === null ? null : (int) $commentable->getKey(),
            ],
        );
    }

    public static function parentOnAnotherSubject(Comment $parent): self
    {
        return new self(
            __('actions.comments.parent_on_another_subject'),
            [
                'parent_id' => (int) $parent->getKey(),
                'parent_commentable_type' => (string) $parent->commentable_type,
                'parent_commentable_id' => (int) $parent->commentable_id,
            ],
        );
    }

    public static function parentIsAReply(Comment $parent): self
    {
        return new self(
            __('actions.comments.parent_is_a_reply'),
            [
                'parent_id' => (int) $parent->getKey(),
                'grandparent_id' => (int) $parent->parent_id,
            ],
        );
    }

    public static function invalidReaction(string $emoji): self
    {
        return new self(
            __('actions.comments.invalid_reaction'),
            ['emoji' => $emoji],
        );
    }
}
