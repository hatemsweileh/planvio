<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Locale;
use App\Models\User;
use App\Notifications\Channels\WorkspaceDatabaseChannel;
use App\Services\NotificationDispatcher;
use App\Services\PreferredNotification;
use App\Support\Branding;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Everything every Planvio notification has to get right, in one place.
 *
 * Four rules are enforced here rather than restated fourteen times:
 *
 *   - **Queued, after commit.** Notifications are raised from inside Actions, which run in
 *     transactions. `ShouldQueueAfterCommit` (a `ShouldQueue`) holds the job until the
 *     transaction lands, so a rolled-back change can never announce itself and a worker can
 *     never read a row that has not been written.
 *   - **Channels come from the reader, not the sender.** `via()` asks
 *     {@see NotificationDispatcher} which channels this person accepts for this category.
 *     That holds whichever way the notification was sent: through the dispatcher, which
 *     pre-groups recipients by channel set, or directly with `$user->notify()`.
 *   - **The database row is self-contained.** `toDatabase()` must carry a rendered title and
 *     body, the actor's name and a URL, because the inbox renders straight out of the JSON
 *     and a list of thirty rows cannot afford thirty joins. The structured ids travel
 *     alongside so a row stays useful to code as well as to a reader.
 *   - **Mail goes through one template.** {@see planvioMail()} is the only way a Planvio
 *     notification builds a `MailMessage`, so the branded layout, the plain-text part and
 *     the footer are identical everywhere.
 *
 * Not enforced here, because it is not a property of a notification: never notifying the
 * person who caused the event. That is a recipient rule, and it belongs to whoever chooses
 * the recipients — {@see NotificationDispatcher::recipients()} for the dispatcher path, and
 * the listener otherwise.
 */
abstract class PlanvioNotification extends Notification implements PreferredNotification, ShouldQueueAfterCommit
{
    use Queueable;

    /**
     * Channels chosen for one group of recipients, or null while none have been chosen.
     *
     * @var list<string>|null
     */
    protected ?array $preferredChannels = null;

    /**
     * The preference key this notification is filed under, e.g. `task.assigned`. Also written
     * to `notifications.category` (ARCHITECTURE.md §5.4).
     */
    abstract public function category(): string;

    abstract public function workspaceId(): ?int;

    abstract public function projectId(): ?int;

    /**
     * The stored payload. See the class docblock: it must render an inbox row on its own.
     *
     * @return array<string, mixed>
     */
    abstract public function toDatabase(object $notifiable): array;

    abstract public function toMail(object $notifiable): MailMessage;

    /**
     * Whether the AI caused this. Drives `notifications.is_ai`, which the inbox uses to mark
     * agent activity apart from a colleague's.
     */
    public function isAi(): bool
    {
        return false;
    }

    /**
     * @param list<string> $channels
     */
    public function onChannels(array $channels): static
    {
        $copy = clone $this;
        $copy->preferredChannels = array_values($channels);

        return $copy;
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        foreach ($this->preferredChannels ?? $this->channelsFor($notifiable) as $channel) {
            $resolved = match ($channel) {
                NotificationDispatcher::CHANNEL_DATABASE => WorkspaceDatabaseChannel::class,
                NotificationDispatcher::CHANNEL_MAIL => 'mail',
                default => null,
            };

            if ($resolved !== null && ! in_array($resolved, $channels, true)) {
                $channels[] = $resolved;
            }
        }

        return $channels;
    }

    /**
     * Kept in step with `toDatabase()` so the `array` channel — which tests and the API use —
     * never sees a different shape from the inbox.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * The common half of every stored payload.
     *
     * `title` and `body` are rendered here, at write time, rather than re-translated on
     * every render: the alternative is an inbox re-translating thirty rows from thirty
     * different structured shapes, and the structured ids are stored alongside anyway, so a
     * screen that wants to re-render a row still can. The language they are rendered in is
     * the *reader's*, because {@see User::preferredLocale()} tells Laravel which locale to
     * wrap the whole send in — one notification sent to five people is written five times,
     * each in the language that person chose.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function payload(
        string $title,
        string $body,
        string $url,
        ?User $actor,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $extra = [],
    ): array {
        return [
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'category' => $this->category(),
            'is_ai' => $this->isAi(),
            'actor_id' => $actor === null ? null : (int) $actor->getKey(),
            'actor_name' => $actor?->name,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'workspace_id' => $this->workspaceId(),
            'project_id' => $this->projectId(),
        ] + $extra;
    }

    /**
     * A branded message, rendered by resources/views/mail/message.blade.php with a
     * text/plain twin.
     *
     * @param list<string> $intro body paragraphs above the button
     * @param list<array{label: string, value: string}> $meta the facts table
     * @param list<string> $outro body paragraphs below the button
     * @param list<string>|null $footer overrides the standard footer
     */
    protected function planvioMail(
        string $subject,
        string $title,
        array $intro = [],
        array $meta = [],
        ?string $actionText = null,
        ?string $actionUrl = null,
        array $outro = [],
        ?string $greeting = null,
        ?string $preheader = null,
        ?array $footer = null,
    ): MailMessage {
        $branding = app(Branding::class);
        $appName = $branding->name();

        $direction = Locale::directionFor(app()->getLocale());
        $rtl = $direction === Locale::RTL;

        $data = [
            'appName' => $appName,
            /*
             | Mail is the one surface that leaves the application, so it cannot read the
             | direction SetLocale shares with a web view: a queued notification renders in
             | a worker with no request at all.
             |
             | The consequences of that direction are resolved here too, rather than in the
             | templates, for two reasons. A mail client has no logical properties — Outlook
             | renders through Word, which supports neither `padding-inline-start` nor
             | `text-align: start`, and `align="left"` is an attribute with no logical
             | spelling at all — so every side has to be written out physically. And a Blade
             | section in the child template is evaluated before its layout runs, so a
             | variable the layout defined would not reach `mail.message` at all.
             */
            'direction' => $direction,
            'near' => $rtl ? 'right' : 'left',
            'far' => $rtl ? 'left' : 'right',
            /*
             | Arabic is cursive: the optical tightening that suits Inter pulls its joins
             | apart rather than opening the word up. The same decision as the `:lang(ar)`
             | block in resources/css/app.css, applied inline because a mail client cannot
             | be relied on to apply a stylesheet at all.
             */
            'tracking' => $rtl ? 'normal' : '-0.01em',
            'headingTracking' => $rtl ? 'normal' : '-0.02em',
            /*
             | No web font reaches a mail client — remote faces are blocked or unsupported
             | almost everywhere — so the stack has to name faces already on the machine.
             | Segoe UI Arabic and Tahoma carry the script on Windows, Geeza Pro on Apple
             | platforms, Noto Sans Arabic on Android and most Linux desktops, and Arial is
             | the last resort that still has it. Without them an Arabic message falls back
             | to whatever the client happens to pick.
             */
            'font' => "-apple-system,BlinkMacSystemFont,'Segoe UI','Segoe UI Arabic',Inter,Roboto,"
                ."'Geeza Pro','Noto Sans Arabic',Tahoma,Helvetica,Arial,sans-serif",
            'accent' => $branding->primaryColor(),
            'markUrl' => $this->markUrl($branding),
            'preheader' => $preheader ?? ($intro[0] ?? $title),
            'title' => $title,
            'greeting' => $greeting,
            'intro' => array_values($intro),
            'meta' => array_values($meta),
            'actionText' => $actionText,
            'actionUrl' => $actionUrl,
            'outro' => array_values($outro),
            'footerLines' => $footer ?? $this->defaultFooter($appName),
        ];

        return (new MailMessage)
            ->subject($subject)
            ->view(['html' => 'mail.message', 'text' => 'mail.message-text'], $data);
    }

    /**
     * @return list<string>
     */
    protected function defaultFooter(string $appName): array
    {
        $lines = [
            __('You are receiving this because of your notification settings in :app.', ['app' => $appName]),
        ];

        $support = config('planvio.brand.support_url');

        if (is_string($support) && $support !== '') {
            $lines[] = __('Need help? :url', ['url' => $support]);
        }

        return $lines;
    }

    /**
     * The channels this notifiable accepts for this category.
     *
     * An anonymous notifiable — `Notification::route('mail', $address)` — has no stored
     * preferences and no inbox to write to, so mail is the only channel that can reach it.
     *
     * @return list<string>
     */
    private function channelsFor(object $notifiable): array
    {
        if ($notifiable instanceof User) {
            return app(NotificationDispatcher::class)->channelsFor($notifiable, $this->category());
        }

        return [NotificationDispatcher::CHANNEL_MAIL];
    }

    /**
     * A raster mark, because no mail client renders SVG. A white-label install that has
     * uploaded a PNG or JPEG icon gets its own; anything else falls back to the shipped
     * Planvio mark rather than sending a broken image.
     */
    private function markUrl(Branding $branding): string
    {
        $icon = $branding->icon();
        $extension = mb_strtolower((string) pathinfo(parse_url($icon, PHP_URL_PATH) ?: $icon, PATHINFO_EXTENSION));

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif'], true)) {
            return str_starts_with($icon, 'http') ? $icon : url($icon);
        }

        return url('/img/brand/planvio-mark-512.png');
    }
}
