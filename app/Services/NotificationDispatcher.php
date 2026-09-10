<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifier;

/**
 * The one place notification policy lives.
 *
 * Actions decide that something happened and who cares about it; this decides whether those
 * people actually hear about it, and how. Keeping that in one class is the point — the rules
 * below are the kind that get quietly forgotten in one call site out of thirty, and each
 * omission is a bug someone experiences as noise:
 *
 *   - **Never notify the actor.** Being told about your own click is the fastest way to make
 *     someone mute a product entirely. The actor defaults to the authenticated user, so a
 *     caller has to opt *in* to self-notification rather than remember to opt out.
 *   - **One notification per person.** Watcher, assignee and mentioned-in-the-comment is one
 *     human, not three.
 *   - **Respect preferences.** `users.notification_preferences` decides the channels, per
 *     category, with a global switch above it.
 *   - **Only members.** When a workspace is given, membership is re-checked here rather than
 *     trusted from the caller's recipient list (§3: every layer holds independently).
 *   - **Only active accounts.** A deactivated user is not emailed.
 *
 * Recipient filtering costs one query however many recipients there are. Channel selection
 * costs none: it is read off a column already loaded with the user.
 *
 * Note for the notification layer: `notifications` carries `workspace_id`, `project_id`,
 * `category` and `is_ai` beyond Laravel's defaults, and the framework's DatabaseChannel does
 * not write columns it does not know about. Filling them needs a custom database channel in
 * `App\Notifications`; this dispatcher deliberately does not reach into the notification's
 * payload to do it.
 */
final class NotificationDispatcher
{
    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_MAIL = 'mail';

    /** What a person gets when they have expressed no preference. */
    public const DEFAULT_CHANNELS = [self::CHANNEL_DATABASE, self::CHANNEL_MAIL];

    public function __construct(private readonly AuthFactory $auth) {}

    /**
     * @param iterable<User>|User $recipients
     * @param string|null $category falls back to the notification's own category
     * @param User|null $actor defaults to the authenticated user
     * @param Workspace|null $workspace when given, non-members are dropped
     * @param bool $includeActor deliver to the actor too — for confirmations they asked for
     * @return int the number of people notified
     */
    public function send(
        iterable|User $recipients,
        Notification $notification,
        ?string $category = null,
        ?User $actor = null,
        ?Workspace $workspace = null,
        bool $includeActor = false,
    ): int {
        $category ??= $notification instanceof PreferredNotification ? $notification->category() : null;

        $audience = $this->recipients($recipients, $actor, $workspace, $includeActor);

        if ($audience === []) {
            return 0;
        }

        // Without the interface the notification's own via() decides the channels, so there
        // is nothing to select — only the recipient rules above apply.
        if (! $notification instanceof PreferredNotification) {
            $delivered = array_values(array_filter(
                $audience,
                fn (User $user): bool => $this->channelsFor($user, $category) !== [],
            ));

            if ($delivered === []) {
                return 0;
            }

            Notifier::send($delivered, $notification);

            return count($delivered);
        }

        // Group by identical channel sets so a hundred recipients cost a handful of sends
        // rather than a hundred.
        /** @var array<string, list<User>> $groups */
        $groups = [];

        /** @var array<string, list<string>> $channelsByKey */
        $channelsByKey = [];

        foreach ($audience as $user) {
            $channels = $this->channelsFor($user, $category);

            if ($channels === []) {
                continue;
            }

            $key = implode('|', $channels);
            $groups[$key][] = $user;
            $channelsByKey[$key] = $channels;
        }

        $notified = 0;

        foreach ($groups as $key => $group) {
            Notifier::send($group, $notification->onChannels($channelsByKey[$key]));

            $notified += count($group);
        }

        return $notified;
    }

    /**
     * Who, of the people offered, is actually eligible.
     *
     * @param iterable<User>|User $recipients
     * @return list<User>
     */
    public function recipients(
        iterable|User $recipients,
        ?User $actor = null,
        ?Workspace $workspace = null,
        bool $includeActor = false,
    ): array {
        $actor ??= $this->authenticatedUser();
        $actorId = $actor?->getKey();

        /** @var array<int, User> $unique */
        $unique = [];

        foreach ($recipients instanceof User ? [$recipients] : $recipients as $user) {
            if (! $user instanceof User) {
                continue;
            }

            $id = $user->getKey();

            if ($id === null) {
                continue;
            }

            if (! $includeActor && $actorId !== null && (int) $id === (int) $actorId) {
                continue;
            }

            if (! $user->is_active) {
                continue;
            }

            $unique[(int) $id] = $user;
        }

        if ($unique === [] || ! $workspace instanceof Workspace) {
            return array_values($unique);
        }

        // One membership lookup for the whole audience. Calling roleIn() per user would be a
        // query per recipient on a path that already fans out.
        $members = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('user_id', array_keys($unique))
            ->pluck('user_id')
            ->all();

        $eligible = [];

        foreach ($members as $userId) {
            $eligible[] = $unique[(int) $userId];
        }

        return $eligible;
    }

    /**
     * The channels this person accepts for this category.
     *
     * Precedence, narrowest first: a category's per-channel setting beats the global
     * per-channel setting, which beats the default. `mute_all` and a category switched off
     * outright short-circuit everything below them.
     *
     * @return list<string>
     */
    public function channelsFor(User $user, ?string $category = null): array
    {
        $preferences = $user->notification_preferences;

        if (! is_array($preferences)) {
            return $this->deliverable($user, self::DEFAULT_CHANNELS);
        }

        if (($preferences['mute_all'] ?? false) === true) {
            return [];
        }

        $global = is_array($preferences['channels'] ?? null) ? $preferences['channels'] : [];
        $categorySetting = $category === null
            ? null
            : (is_array($preferences['categories'] ?? null) ? ($preferences['categories'][$category] ?? null) : null);

        if ($categorySetting === false) {
            return [];
        }

        $perCategory = is_array($categorySetting) ? $categorySetting : [];

        $channels = [];

        foreach (self::DEFAULT_CHANNELS as $channel) {
            $allowed = $perCategory[$channel] ?? $global[$channel] ?? true;

            if ($allowed !== false) {
                $channels[] = $channel;
            }
        }

        return $this->deliverable($user, $channels);
    }

    /**
     * Whether one channel would be used for one category — what a preference screen renders,
     * and what a test asserts against.
     */
    public function wants(User $user, ?string $category, string $channel): bool
    {
        return in_array($channel, $this->channelsFor($user, $category), true);
    }

    /**
     * Drop channels that cannot physically deliver, whatever the preference says.
     *
     * @param list<string> $channels
     * @return list<string>
     */
    private function deliverable(User $user, array $channels): array
    {
        $email = $user->email;

        if (is_string($email) && $email !== '') {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            static fn (string $channel): bool => $channel !== self::CHANNEL_MAIL,
        ));
    }

    private function authenticatedUser(): ?User
    {
        $user = $this->auth->guard()->user();

        return $user instanceof User ? $user : null;
    }
}
