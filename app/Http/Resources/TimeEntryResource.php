<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimeEntry;
use Illuminate\Http\Request;

/**
 * One logged period of work.
 *
 * Durations are minutes, everywhere in Planvio (ARCHITECTURE.md §5). There is no `hours`
 * field and no decimal: an integer number of minutes is the only representation that
 * survives a round trip without a rounding argument.
 *
 * `started_at`/`ended_at` are only set for entries produced by the timer; a manually logged
 * hour has `minutes` and `spent_on` and nothing else, which is why `minutes` — not the
 * difference between the two instants — is the authoritative duration.
 *
 * @property TimeEntry $resource
 */
final class TimeEntryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => (int) $entry->getKey(),
            'workspace_id' => self::id($entry->workspace_id),
            'project_id' => self::id($entry->project_id),
            'task_id' => self::id($entry->task_id),
            'user_id' => self::id($entry->user_id),
            'minutes' => (int) $entry->minutes,
            'description' => $entry->description === null ? null : (string) $entry->description,
            'spent_on' => self::date($entry->spent_on),
            'started_at' => self::iso($entry->started_at),
            'ended_at' => self::iso($entry->ended_at),
            'is_running' => (bool) $entry->is_running,
            'is_billable' => (bool) $entry->is_billable,
            'created_at' => self::iso($entry->created_at),
            'updated_at' => self::iso($entry->updated_at),

            'user' => $this->whenLoaded('user', fn (): ?array => $entry->user === null
                ? null
                : (new UserResource($entry->user))->resolve($request)),
        ];
    }
}
