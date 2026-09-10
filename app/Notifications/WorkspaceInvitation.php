<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Invitation;
use App\Notifications\Support\PlanvioUrl;
use App\Support\Branding;
use App\Support\Formats;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The invitation e-mail.
 *
 * Queued, and queued after commit — the base class guarantees both — because the action that
 * creates an invitation does so inside a transaction, and a worker that picked the job up
 * first would look for a row that has not been written yet.
 *
 * **Mail is the only channel, and that is a security property rather than a preference.**
 * The accept link carries the invitation token, which is a bearer credential: writing it to
 * `notifications.data` would leave a working credential readable by anyone who can query the
 * table or read a backup. `via()` is therefore hard-coded rather than resolved from the
 * recipient's preferences, and `toDatabase()` — which nothing reaches, but which the base
 * class requires — is deliberately token-free so that stays true if someone ever routes this
 * notification somewhere new.
 *
 * The recipient is usually an anonymous notifiable (`Notification::route('mail', $address)`):
 * an invited person has no account yet, and so no preferences to consult.
 */
final class WorkspaceInvitation extends PlanvioNotification
{
    public function __construct(private readonly Invitation $invitation) {}

    public function category(): string
    {
        return 'workspace.invitation';
    }

    public function workspaceId(): ?int
    {
        return (int) $this->invitation->workspace_id;
    }

    public function projectId(): ?int
    {
        $projectId = $this->invitation->project_id;

        return $projectId === null ? null : (int) $projectId;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $invitation = $this->context();

        return $this->payload(
            title: __('You have been invited to join :workspace', [
                'workspace' => $this->workspaceName($invitation),
            ]),
            body: __('Check your e-mail for the invitation link.'),
            url: PlanvioUrl::inbox($invitation->workspace),
            actor: $invitation->inviter,
            subjectType: $invitation->getMorphClass(),
            subjectId: (int) $invitation->getKey(),
            extra: [
                'invitation_id' => (int) $invitation->getKey(),
                'workspace_name' => $this->workspaceName($invitation),
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->context();
        $appName = app(Branding::class)->name();
        $workspaceName = $this->workspaceName($invitation);
        $inviterName = (string) ($invitation->inviter?->name ?? $appName);

        $meta = [
            ['label' => __('Workspace'), 'value' => $workspaceName],
            ['label' => __('Invited by'), 'value' => $inviterName],
            ['label' => __('Your role'), 'value' => $invitation->role->label()],
        ];

        if ($invitation->project !== null) {
            $meta[] = ['label' => __('Project'), 'value' => (string) $invitation->project->name];
        }

        $outro = [];

        if ($invitation->expires_at !== null) {
            $outro[] = __('This invitation expires on :date.', [
                // The month name follows the locale; so does the comma between the date and
                // the clock, which Arabic sets as ، rather than as an ASCII comma.
                'date' => $invitation->expires_at->translatedFormat(Formats::punctuate('j M Y, H:i')),
            ]);
        }

        $outro[] = __('If you were not expecting this invitation you can ignore this e-mail.');

        return $this->planvioMail(
            subject: __('You have been invited to join :workspace', ['workspace' => $workspaceName]),
            title: __(':inviter invited you to :workspace', [
                'inviter' => $inviterName,
                'workspace' => $workspaceName,
            ]),
            intro: [__(':inviter has invited you to join :workspace on :app.', [
                'inviter' => $inviterName,
                'workspace' => $workspaceName,
                'app' => $appName,
            ])],
            meta: $meta,
            actionText: __('Accept invitation'),
            actionUrl: PlanvioUrl::invitation((string) $invitation->token),
            outro: $outro,
            greeting: __('Hello!'),
            // The standard footer talks about notification settings, which an invited
            // person does not have yet.
            footer: [__('This invitation was sent by :inviter through :app.', [
                'inviter' => $inviterName,
                'app' => $appName,
            ])],
        );
    }

    private function context(): Invitation
    {
        return $this->invitation->loadMissing(['workspace', 'inviter', 'project']);
    }

    private function workspaceName(Invitation $invitation): string
    {
        $name = $invitation->workspace?->name;

        return is_string($name) && $name !== '' ? $name : app(Branding::class)->name();
    }
}
