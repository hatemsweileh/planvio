<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * The database channel, plus the four tenant columns Planvio adds to `notifications`
 * (ARCHITECTURE.md §5.4): `workspace_id`, `project_id`, `category` and `is_ai`.
 *
 * Laravel writes only id/type/data/read_at, which would leave every notification
 * unattributable to a workspace and force the inbox to unpack the JSON blob to filter.
 * The extra values are read off the notification the same way the framework reads
 * `databaseType()`: by optional method, so an ordinary notification routed here still
 * works and simply leaves the columns null.
 *
 * Reach it from a notification by returning this class name from `via()`.
 */
final class WorkspaceDatabaseChannel extends DatabaseChannel
{
    /**
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        /** @var array<string, mixed> $payload */
        $payload = parent::buildPayload($notifiable, $notification);

        return array_merge($payload, [
            'workspace_id' => method_exists($notification, 'workspaceId')
                ? $notification->workspaceId($notifiable)
                : null,
            'project_id' => method_exists($notification, 'projectId')
                ? $notification->projectId($notifiable)
                : null,
            'category' => method_exists($notification, 'category')
                ? $notification->category($notifiable)
                : null,
            'is_ai' => method_exists($notification, 'isAi')
                ? $notification->isAi($notifiable)
                : false,
        ]);
    }
}
