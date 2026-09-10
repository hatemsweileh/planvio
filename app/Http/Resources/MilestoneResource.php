<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Milestone;
use Illuminate\Http\Request;

/**
 * A milestone.
 *
 * `completed_at` is never writable through the API — it is set by the status transition, so
 * the timestamp and the status can never disagree about whether the milestone is finished.
 * Move a milestone to `completed` and the timestamp appears here on the next read.
 *
 * @property Milestone $resource
 */
final class MilestoneResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $milestone = $this->resource;

        return [
            'id' => (int) $milestone->getKey(),
            'workspace_id' => self::id($milestone->workspace_id),
            'project_id' => self::id($milestone->project_id),
            'name' => (string) $milestone->name,
            'description' => $milestone->description === null ? null : (string) $milestone->description,
            'status' => self::enum($milestone->status),
            'start_date' => self::date($milestone->start_date),
            'due_date' => self::date($milestone->due_date),
            'completed_at' => self::iso($milestone->completed_at),
            'owner_id' => self::id($milestone->owner_id),
            'position' => (int) $milestone->position,
            'progress' => (int) $milestone->progress,
            'created_at' => self::iso($milestone->created_at),
            'updated_at' => self::iso($milestone->updated_at),

            'owner' => $this->whenLoaded('owner', fn (): ?array => $milestone->owner === null
                ? null
                : (new UserResource($milestone->owner))->resolve($request)),
        ];
    }
}
