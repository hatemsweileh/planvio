<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Implemented by notifications that want {@see NotificationDispatcher} to apply a person's
 * preferences to them.
 *
 * A notification that does not implement this is still delivered — on whatever channels its
 * own `via()` declares — and is still subject to the dispatcher's recipient rules. The
 * interface only exists so channel selection can be decided once, centrally, instead of
 * every notification re-reading `users.notification_preferences` in its own `via()`.
 */
interface PreferredNotification
{
    /**
     * The preference key this notification is filed under, e.g. `task.assigned`.
     *
     * The same string is written to `notifications.category` (ARCHITECTURE.md §5.4), so the
     * in-app inbox can filter by exactly what the preference screen switches off.
     */
    public function category(): string;

    /**
     * Return a copy delivering on these channels only.
     *
     * Called once per group of recipients who resolved to the same channels, so the instance
     * must not be mutated in place — two groups would otherwise share the last value set.
     *
     * @param list<string> $channels
     */
    public function onChannels(array $channels): static;
}
