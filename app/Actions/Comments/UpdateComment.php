<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Events\Comments\CommentUpdated;
use App\Exceptions\InvalidComment;
use App\Models\Comment;
use App\Models\User;
use App\Notifications\Comments\MentionedInComment;
use App\Services\ActivityLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Edit the body of an existing comment.
 *
 * The edit goes through the same sanitiser as the original: a comment that was safe when
 * it was posted must not become a payload when it is edited.
 *
 * Mentions are re-resolved and only the *new* ones are notified. Somebody added to a
 * discussion by an edit has no other way to learn about it, while re-notifying the people
 * who were already named would make editing a typo a way to nag a room.
 */
final class UpdateComment
{
    private const EXCERPT_CHARS = 140;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly MentionResolver $mentions,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function __invoke(Comment $comment, User $editor, string $body): Comment
    {
        $clean = $this->sanitizer->sanitize($body);

        if ($clean === '') {
            throw InvalidComment::empty();
        }

        // Re-running the same edit must not stamp a fresh `edited_at` or fire a second
        // round of notifications.
        if ($clean === (string) $comment->body) {
            return $comment;
        }

        $subject = CommentSubject::for($this->commentableOf($comment));
        $previousBody = (string) $comment->body;

        $before = MentionResolver::tokens($previousBody);
        $after = ($this->mentions)($clean, $subject->workspace, $subject->project, $editor);

        $newlyMentioned = $after->reject(
            static fn (User $user): bool => self::wasAlreadyMentioned($user, $before),
        )->values();

        $newlyMentionedIds = $newlyMentioned->map(static fn (User $user): int => (int) $user->getKey())->all();

        DB::transaction(function () use ($comment, $editor, $clean, $previousBody, $subject, $newlyMentionedIds): void {
            $comment->body = $clean;
            $comment->edited_at = Carbon::now();
            $comment->save();

            $this->activity->forUser($editor)->log($subject->commentable, 'comment_updated', [
                'comment_id' => (int) $comment->getKey(),
                'excerpt' => $this->sanitizer->sanitizeExcerpt($clean, self::EXCERPT_CHARS),
                'previous_excerpt' => $this->sanitizer->sanitizeExcerpt($previousBody, self::EXCERPT_CHARS),
                'mentioned_user_ids' => $newlyMentionedIds,
            ]);
        });

        if ($newlyMentioned->isNotEmpty()) {
            $this->notifications->send($newlyMentioned->all(), new MentionedInComment(
                comment: $comment,
                actor: $editor,
                excerpt: $this->sanitizer->sanitizeExcerpt($clean, self::EXCERPT_CHARS),
                projectId: $subject->projectId(),
                subjectType: $subject->commentable->getMorphClass(),
                subjectId: (int) $subject->commentable->getKey(),
                subjectTitle: $subject->title,
            ));
        }

        $this->events->dispatch(new CommentUpdated(
            comment: $comment,
            actor: $editor,
            newlyMentionedUserIds: $newlyMentionedIds,
        ));

        return $comment->refresh();
    }

    /**
     * Comparing tokens rather than resolved users keeps the "already mentioned" test
     * honest for somebody who was named in the old body but was not a member then.
     *
     * @param list<string> $tokens
     */
    private static function wasAlreadyMentioned(User $user, array $tokens): bool
    {
        if ($tokens === []) {
            return false;
        }

        $email = mb_strtolower((string) $user->email, 'UTF-8');
        $localPart = strstr($email, '@', true);
        $name = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $user->name) ?? ''), 'UTF-8');

        $spellings = array_filter([
            $email,
            is_string($localPart) ? $localPart : '',
            $name === '' ? '' : str_replace(' ', '', $name),
            $name === '' ? '' : str_replace(' ', '.', $name),
            $name === '' ? '' : str_replace(' ', '-', $name),
            $name === '' ? '' : str_replace(' ', '_', $name),
            $name === '' ? '' : (string) strtok($name, ' '),
        ]);

        return array_intersect($spellings, $tokens) !== [];
    }

    private function commentableOf(Comment $comment): Model
    {
        $commentable = $comment->commentable;

        if (! $commentable instanceof Model) {
            throw InvalidComment::notCommentable($comment);
        }

        return $commentable;
    }
}
