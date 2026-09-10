<?php

declare(strict_types=1);

namespace App\Notifications\Comments;

use App\Models\Comment;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Tells a watcher that a discussion they follow has moved on.
 *
 * Sent to watchers who were *not* named in the comment: a mention is the louder signal, and
 * nobody should receive both for one comment. The caller subtracts the mentioned set before
 * handing over the recipients.
 */
final class CommentPosted extends PlanvioNotification
{
    public function __construct(
        private readonly Comment $comment,
        private readonly User $actor,
        private readonly string $excerpt,
        private readonly ?int $projectId,
        private readonly string $subjectType,
        private readonly int $subjectId,
        private readonly string $subjectTitle,
    ) {}

    public function category(): string
    {
        return 'comment.posted';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->comment->workspace_id;
    }

    public function projectId(): ?int
    {
        return $this->projectId;
    }

    public function isAi(): bool
    {
        return $this->comment->isFromAi();
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            title: __(':actor commented on :subject', [
                'actor' => (string) $this->actor->name,
                'subject' => $this->subjectTitle,
            ]),
            body: $this->excerpt,
            url: $this->url(),
            actor: $this->actor,
            subjectType: $this->subjectType,
            subjectId: $this->subjectId,
            extra: [
                'comment_id' => (int) $this->comment->getKey(),
                'excerpt' => $this->excerpt,
                'subject_title' => $this->subjectTitle,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->planvioMail(
            subject: __('New comment on :subject', ['subject' => $this->subjectTitle]),
            title: __(':actor commented on :subject', [
                'actor' => (string) $this->actor->name,
                'subject' => $this->subjectTitle,
            ]),
            intro: [$this->excerpt],
            actionText: __('Read the discussion'),
            actionUrl: $this->url(),
            outro: [__('You are following this because you were assigned to it or chose to watch it.')],
        );
    }

    private function url(): string
    {
        return PlanvioUrl::comment(
            $this->comment->loadMissing('workspace'),
            $this->subjectType,
            $this->subjectId,
        );
    }
}
