<?php

declare(strict_types=1);

namespace App\Notifications\Comments;

use App\Models\Comment;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Somebody named this person in a comment.
 *
 * A mention only reaches people who are already members of the project the comment sits in;
 * the resolver in App\Actions\Comments enforces that, so nothing here has to re-check it.
 *
 * What this class must not do is put the comment body in the payload. The body is sanitised
 * HTML and an inbox row is rendered in places that have no business rendering markup, so the
 * caller passes a plain-text excerpt and that is what is stored and mailed.
 */
final class MentionedInComment extends PlanvioNotification
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
        return 'comment.mentioned';
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
            title: __(':actor mentioned you in :subject', [
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
            subject: __(':actor mentioned you in :subject', [
                'actor' => (string) $this->actor->name,
                'subject' => $this->subjectTitle,
            ]),
            title: __(':actor mentioned you', ['actor' => (string) $this->actor->name]),
            intro: [$this->excerpt],
            meta: [
                ['label' => __('Mentioned in'), 'value' => $this->subjectTitle],
            ],
            actionText: __('Read the discussion'),
            actionUrl: $this->url(),
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
