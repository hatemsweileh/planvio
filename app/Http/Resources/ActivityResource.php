<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use App\Policies\ActivityPolicy;
use Illuminate\Http\Request;

/**
 * One entry in a workspace's activity feed.
 *
 * `event` is the stable machine value — `created`, `updated`, `status_changed`, `assigned` —
 * and is what an integration branches on. `description` is a sentence written for a person
 * and may be null; `properties` carries the structured detail behind it, typically the
 * attribute that changed with its old and new values.
 *
 * Activity properties never carry a secret (ARCHITECTURE.md §5.4), which is what makes this
 * column safe to publish. Everything visible here is already visible on the activity screen
 * to the same people, gated by the same {@see ActivityPolicy}.
 *
 * @property Activity $resource
 */
final class ActivityResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $activity = $this->resource;
        $properties = $activity->properties;

        return [
            'id' => (int) $activity->getKey(),
            'workspace_id' => self::id($activity->workspace_id),
            'project_id' => self::id($activity->project_id),
            'subject_type' => (string) $activity->subject_type,
            'subject_id' => self::id($activity->subject_id),
            'causer_id' => self::id($activity->causer_id),
            'causer_type' => self::enum($activity->causer_type),
            'ai_run_id' => self::id($activity->ai_run_id),
            'event' => (string) $activity->event,
            'description' => $activity->description === null ? null : (string) $activity->description,
            'properties' => is_array($properties) ? $properties : null,
            'created_at' => self::iso($activity->created_at),

            'causer' => $this->whenLoaded('causer', fn (): ?array => $activity->causer === null
                ? null
                : (new UserResource($activity->causer))->resolve($request)),
        ];
    }
}
