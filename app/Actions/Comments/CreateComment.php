<?php

declare(strict_types=1);

namespace App\Actions\Comments;

use App\Enums\AuthorType;
use App\Events\Comments\CommentCreated;
use App\Exceptions\InvalidComment;
use App\Exceptions\WorkspaceMismatch;
use App\Models\AiRun;
use App\Models\Comment;
use App\Models\Task;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Notifications\Comments\CommentPosted;
use App\Notifications\Comments\MentionedInComment;
use App\Services\ActivityLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Post a comment on anything that carries a workspace.
 *
 * Three things happen here that must not be skipped by any caller:
 *
 * 1. the body is sanitised before it is stored, never on the way out (§5.4);
 * 2. `@mentions` are resolved against the project's own membership, so a mention cannot be
 *    used to push a notification — and a snippet of a private discussion — at somebody who
 *    was never given access to the project;
 * 3. mentioned members and watchers are notified once each, never twice.
 *
 * Authorization is the caller's job, as always. What is checked here is that the reply
 * belongs on the discussion it claims, and that the comment is not an accidental duplicate
 * of one posted seconds earlier.
 */
final class CreateComment
{
    /**
     * A second identical comment on the same subject within this many seconds is a double
     * submit — a resubmitted form, a retried job, an agent repeating a tool call — not a
     * person saying the same thing twice.
     */
    private const DUPLICATE_WINDOW_SECONDS = 10;

    private const EXCERPT_CHARS = 140;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly MentionResolver $mentions,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
        private readonly NotificationDispatcher $notifications,
    ) {}

    public function __invoke(
        Model $commentable,
        User $author,
        string $body,
        ?Comment $parent = null,
        AuthorType $authorType = AuthorType::User,
        ?AiRun $aiRun = null,
    ): Comment {
        $clean = $this->sanitizer->sanitize($body);

        if ($clean === '') {
            throw InvalidComment::empty();
        }

        $subject = CommentSubject::for($commentable);

        if ($parent !== null) {
            $this->assertParentBelongsHere($parent, $subject);
        }

        $existing = $this->recentDuplicate($subject, $author, $clean, $parent);

        if ($existing instanceof Comment) {
            return $existing;
        }

        $mentioned = ($this->mentions)($clean, $subject->workspace, $subject->project, $author);
        $mentionedIds = $mentioned->map(static fn (User $user): int => (int) $user->getKey())->all();

        $comment = DB::transaction(function () use (
            $subject,
            $author,
            $clean,
            $parent,
            $authorType,
            $aiRun,
            $mentionedIds,
        ): Comment {
            $comment = Comment::query()->create([
                'workspace_id' => $subject->workspaceId(),
                'commentable_id' => $subject->commentable->getKey(),
                'commentable_type' => $subject->commentable->getMorphClass(),
                'user_id' => $author->getKey(),
                'body' => $clean,
                'author_type' => $authorType,
                'ai_run_id' => $aiRun?->getKey(),
                'parent_id' => $parent?->getKey(),
            ]);

            $logger = $aiRun instanceof AiRun
                ? $this->activity->forAi($aiRun)
                : $this->activity->forUser($author);

            $logger->log($subject->commentable, 'commented', [
                'comment_id' => (int) $comment->getKey(),
                'parent_id' => $parent === null ? null : (int) $parent->getKey(),
                'excerpt' => $this->sanitizer->sanitizeExcerpt($clean, self::EXCERPT_CHARS),
                'mentioned_user_ids' => $mentionedIds,
            ]);

            return $comment;
        });

        $watchers = $this->watchers($subject, $author, $mentionedIds);

        $this->notify($comment, $subject, $author, $mentioned, $watchers);

        $notifiedIds = array_values(array_unique(array_merge(
            $mentionedIds,
            $watchers->map(static fn (User $user): int => (int) $user->getKey())->all(),
        )));

        $this->events->dispatch(new CommentCreated(
            comment: $comment,
            commentable: $subject->commentable,
            actor: $author,
            mentionedUserIds: $mentionedIds,
            notifiedUserIds: $notifiedIds,
        ));

        return $comment;
    }

    /**
     * A reply has to answer a comment on the same subject, and the thread is two levels
     * deep: replying to a reply files the answer under the comment that started it, which
     * is the shape the UI renders.
     */
    private function assertParentBelongsHere(Comment $parent, CommentSubject $subject): void
    {
        if ((int) $parent->workspace_id !== $subject->workspaceId()) {
            throw WorkspaceMismatch::between(
                Comment::class,
                (int) $parent->workspace_id,
                $subject->commentable->getMorphClass(),
                $subject->workspaceId(),
            );
        }

        $sameSubject = $parent->commentable_type === $subject->commentable->getMorphClass()
            && (int) $parent->commentable_id === (int) $subject->commentable->getKey();

        if (! $sameSubject) {
            throw InvalidComment::parentOnAnotherSubject($parent);
        }

        if ($parent->parent_id !== null) {
            throw InvalidComment::parentIsAReply($parent);
        }
    }

    private function recentDuplicate(
        CommentSubject $subject,
        User $author,
        string $body,
        ?Comment $parent,
    ): ?Comment {
        return Comment::withoutWorkspaceScope()
            ->where('workspace_id', $subject->workspaceId())
            ->where('commentable_type', $subject->commentable->getMorphClass())
            ->where('commentable_id', $subject->commentable->getKey())
            ->where('user_id', $author->getKey())
            ->where('parent_id', $parent?->getKey())
            ->where('body', $body)
            ->where('created_at', '>=', Carbon::now()->subSeconds(self::DUPLICATE_WINDOW_SECONDS))
            ->latest('id')
            ->first();
    }

    /**
     * The people following this subject, minus the author and anyone already mentioned:
     * one comment must never produce two inbox rows for the same person.
     *
     * Watching is only defined for tasks, and the list is intersected with workspace
     * membership so a stale watcher row left behind by a removed member cannot keep
     * receiving the project's discussion.
     *
     * @param list<int> $mentionedIds
     * @return EloquentCollection<int, User>
     */
    private function watchers(CommentSubject $subject, User $author, array $mentionedIds): EloquentCollection
    {
        if (! $subject->commentable instanceof Task) {
            /** @var EloquentCollection<int, User> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        $excluded = array_values(array_unique(array_merge($mentionedIds, [(int) $author->getKey()])));

        $watcherIds = TaskWatcher::query()
            ->where('task_id', $subject->commentable->getKey())
            ->whereNotIn('user_id', $excluded)
            ->whereIn('user_id', WorkspaceMember::withoutWorkspaceScope()
                ->where('workspace_id', $subject->workspaceId())
                ->select('user_id'))
            ->pluck('user_id')
            ->all();

        if ($watcherIds === []) {
            /** @var EloquentCollection<int, User> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        /** @var EloquentCollection<int, User> $users */
        $users = User::query()
            ->whereIn('id', $watcherIds)
            ->where('is_active', true)
            ->get();

        return $users;
    }

    /**
     * @param EloquentCollection<int, User>|Collection<int, User> $mentioned
     * @param EloquentCollection<int, User> $watchers
     */
    private function notify(
        Comment $comment,
        CommentSubject $subject,
        User $author,
        iterable $mentioned,
        EloquentCollection $watchers,
    ): void {
        $excerpt = $this->sanitizer->sanitizeExcerpt((string) $comment->body, self::EXCERPT_CHARS);

        $recipients = [];

        foreach ($mentioned as $user) {
            $recipients[] = $user;
        }

        if ($recipients !== []) {
            $this->notifications->send($recipients, new MentionedInComment(
                comment: $comment,
                actor: $author,
                excerpt: $excerpt,
                projectId: $subject->projectId(),
                subjectType: $subject->commentable->getMorphClass(),
                subjectId: (int) $subject->commentable->getKey(),
                subjectTitle: $subject->title,
            ));
        }

        if ($watchers->isNotEmpty()) {
            $this->notifications->send($watchers, new CommentPosted(
                comment: $comment,
                actor: $author,
                excerpt: $excerpt,
                projectId: $subject->projectId(),
                subjectType: $subject->commentable->getMorphClass(),
                subjectId: (int) $subject->commentable->getKey(),
                subjectTitle: $subject->title,
            ));
        }
    }
}
