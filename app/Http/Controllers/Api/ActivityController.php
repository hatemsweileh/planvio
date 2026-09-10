<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\WorkspaceRole;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ApiResponse;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Policies\ActivityPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The activity feed, read-only.
 *
 * There is no write path, and not because one was left out: the feed is append-only by
 * design, every mutating method on {@see ActivityPolicy} is a flat refusal, and
 * the AI layer's accountability rests on the fact that nothing but an Action can write here
 * (ARCHITECTURE.md §7.1).
 *
 * The visibility rule mirrors the policy exactly. An entry carries its own `project_id`, so a
 * guest sees the projects they are a member of and nothing else — including nothing from the
 * workspace-level entries, which have no project for the guest's `*` cell to match against.
 */
final class ActivityController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $this->actor($request);

        $this->authorize('viewAny', Activity::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:191'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $visibleProjects = Project::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->select('projects.id')
            ->where('projects.workspace_id', $workspace->getKey())
            ->visibleTo($user);

        $isGuest = $user->roleIn($workspace) === WorkspaceRole::Guest;

        $query = Activity::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('activities.workspace_id', $workspace->getKey())
            ->with('causer')
            ->when(
                $isGuest,
                fn (Builder $q): Builder => $q->whereIn('activities.project_id', $visibleProjects),
                fn (Builder $q): Builder => $q->where(function (Builder $scope) use ($visibleProjects): void {
                    $scope->whereNull('activities.project_id')
                        ->orWhereIn('activities.project_id', $visibleProjects);
                }),
            )
            ->when(isset($filters['project_id']), fn (Builder $q): Builder => $q->where('activities.project_id', $filters['project_id']))
            ->when(isset($filters['event']), fn (Builder $q): Builder => $q->where('activities.event', $filters['event']))
            ->when(isset($filters['subject_type']), fn (Builder $q): Builder => $q->where('activities.subject_type', $filters['subject_type']))
            ->when(isset($filters['subject_id']), fn (Builder $q): Builder => $q->where('activities.subject_id', $filters['subject_id']))
            ->when(isset($filters['since']), fn (Builder $q): Builder => $q->where('activities.created_at', '>=', (string) $filters['since']))
            ->orderByDesc('activities.created_at')
            ->orderByDesc('activities.id');

        return ApiResponse::paginated($this->paginate($request, $query), ActivityResource::class);
    }
}
