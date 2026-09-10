<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write\Support;

use App\Models\AiRun;
use App\Models\User;
use App\Notifications\PlanvioNotification;
use App\Notifications\Support\PlanvioUrl;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The notification `send_notification` sends: a short message from the assistant to people
 * who are already members of this workspace.
 *
 * Everything about it is built to make the sender obvious. `is_ai` is true, so the inbox
 * marks it as agent activity rather than a colleague's; the acting user is named in the body
 * rather than set as the actor, so the row can never render as though a person wrote it; and
 * the link goes to the run, so a recipient who wants to know *why* they were told something
 * lands on the full tool sequence rather than on a dead end.
 *
 * The body is workspace-derived text written by a model. It is stored and mailed as plain
 * text, never as markup, and it is bounded by the tool before it reaches the constructor —
 * a notification payload is copied into mail, into browser storage and into whatever renders
 * an inbox row, and none of those places should be asked to cope with an unbounded string.
 */
final class AssistantMessage extends PlanvioNotification
{
    public function __construct(
        private readonly AiRun $run,
        private readonly User $actingUser,
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly ?string $link = null,
    ) {}

    public function category(): string
    {
        return 'ai.message';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->run->workspace_id;
    }

    public function projectId(): ?int
    {
        return $this->run->project_id === null ? null : (int) $this->run->project_id;
    }

    public function isAi(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload(
            title: $this->subjectLine,
            body: $this->body,
            url: $this->url(),
            // Deliberately null: an assistant message has no human author, and filling the
            // actor would let the inbox render it as one.
            actor: null,
            extra: [
                'ai_run_id' => (int) $this->run->getKey(),
                'ai_run_uuid' => (string) $this->run->uuid,
                'acting_for_id' => (int) $this->actingUser->getKey(),
                'acting_for' => $this->actingUser->name,
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->planvioMail(
            subject: $this->subjectLine,
            title: $this->subjectLine,
            intro: [$this->body],
            meta: [
                ['label' => __('Sent by'), 'value' => __('The assistant')],
                ['label' => __('Acting for'), 'value' => (string) $this->actingUser->name],
            ],
            actionText: __('See what the assistant did'),
            actionUrl: $this->url(),
            outro: [__('Every step of this run is recorded and can be reviewed in full.')],
        );
    }

    private function url(): string
    {
        if ($this->link !== null && $this->link !== '') {
            return $this->link;
        }

        return PlanvioUrl::aiRun($this->run->workspace, (string) $this->run->uuid);
    }
}
