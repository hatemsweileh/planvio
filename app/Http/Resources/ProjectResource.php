<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Http\Request;

/**
 * A project.
 *
 * Two columns are withheld and one is conditional.
 *
 * `settings` and `ai_settings` are free-form JSON the product writes into. Publishing them
 * would make every internal preference part of the API contract, and would publish whatever
 * a later release decided to keep there without anybody reviewing the decision.
 *
 * `budget` and `currency` are sent only to a caller who holds `budget.view` for this
 * project, asked through the same {@see ProjectPolicy::viewBudget()} the
 * product UI asks. The matrix gives that permission to owners and admins outright and to a
 * manager only inside projects they manage (ARCHITECTURE.md §4.2), so a plain member reading
 * the API sees exactly what they would see on the screen — the fields are absent, not null,
 * because a null would say "this project has no budget" rather than "you may not see it".
 *
 * @property Project $resource
 */
final class ProjectResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $project = $this->resource;

        $payload = [
            'id' => (int) $project->getKey(),
            'workspace_id' => self::id($project->workspace_id),
            'key' => (string) $project->key,
            'name' => (string) $project->name,
            'slug' => (string) $project->slug,
            'description' => $project->description === null ? null : (string) $project->description,
            'icon' => $project->icon === null ? null : (string) $project->icon,
            'color' => (string) $project->color,
            'type' => self::enum($project->type),
            'status_id' => self::id($project->status_id),
            'health' => self::enum($project->health),
            'health_note' => $project->health_note === null ? null : (string) $project->health_note,
            'priority' => self::enum($project->priority),
            'owner_id' => self::id($project->owner_id),
            'manager_id' => self::id($project->manager_id),
            'client_name' => $project->client_name === null ? null : (string) $project->client_name,
            'department' => $project->department === null ? null : (string) $project->department,
            'start_date' => self::date($project->start_date),
            'target_date' => self::date($project->target_date),
            'completed_at' => self::iso($project->completed_at),
            'progress' => (int) $project->progress,
            'is_archived' => (bool) $project->is_archived,
            'archived_at' => self::iso($project->archived_at),
            'created_at' => self::iso($project->created_at),
            'updated_at' => self::iso($project->updated_at),
            'url' => self::appUrl('/projects/'.$project->slug),

            'status' => $this->whenLoaded('status', fn (): ?array => $project->status === null
                ? null
                : (new ProjectStatusResource($project->status))->resolve($request)),
            'owner' => $this->whenLoaded('owner', fn (): ?array => $project->owner === null
                ? null
                : (new UserResource($project->owner))->resolve($request)),
            'manager' => $this->whenLoaded('manager', fn (): ?array => $project->manager === null
                ? null
                : (new UserResource($project->manager))->resolve($request)),
        ];

        if ($this->mayReadBudget($request, $project)) {
            $payload['budget'] = $project->budget === null ? null : (string) $project->budget;
            $payload['currency'] = $project->currency === null ? null : (string) $project->currency;
        }

        return $payload;
    }

    private function mayReadBudget(Request $request, Project $project): bool
    {
        $user = $request->user();

        return $user instanceof User && $user->can('viewBudget', $project);
    }
}
