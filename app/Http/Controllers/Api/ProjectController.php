<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Projects\CreateProject;
use App\Actions\Projects\DeleteProject;
use App\Actions\Projects\UpdateProject;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectType;
use App\Http\Requests\Api\StoreProjectRequest;
use App\Http\Requests\Api\UpdateProjectRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Policies\ProjectPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Projects.
 *
 * ## Visibility
 *
 * The list is narrowed by {@see Project::scopeVisibleTo()} before anything else happens. A
 * workspace member above guest sees every project; a guest sees only the projects they were
 * explicitly added to. That is the query-side half of the same rule
 * {@see ProjectPolicy} enforces per record — and it has to be applied here,
 * because a list filtered after the fact has already told the caller how many rows there
 * were.
 */
final class ProjectController extends ApiController
{
    /**
     * Columns a caller may sort by. Never the raw query value: `orderBy` interpolates.
     *
     * @var array<string, string>
     */
    private const SORTS = [
        'name' => 'name',
        'key' => 'key',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'target_date' => 'target_date',
        'progress' => 'progress',
    ];

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $this->actor($request);

        $this->authorize('viewAny', [Project::class, $workspace]);

        $filters = $request->validate([
            'archived' => ['nullable', 'boolean'],
            'status_id' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', Rule::enum(ProjectType::class)],
            'health' => ['nullable', Rule::enum(ProjectHealth::class)],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'q' => ['nullable', 'string', 'max:128'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$column, $direction] = $this->sort($request, self::SORTS, 'updated_at');

        $query = Project::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->visibleTo($user)
            ->with('status')
            ->where('is_archived', (bool) ($filters['archived'] ?? false))
            ->when(isset($filters['status_id']), fn (Builder $q): Builder => $q->where('status_id', $filters['status_id']))
            ->when(isset($filters['type']), fn (Builder $q): Builder => $q->where('type', $filters['type']))
            ->when(isset($filters['health']), fn (Builder $q): Builder => $q->where('health', $filters['health']))
            ->when(isset($filters['priority']), fn (Builder $q): Builder => $q->where('priority', $filters['priority']))
            ->when(
                isset($filters['q']) && trim((string) $filters['q']) !== '',
                function (Builder $q) use ($filters): Builder {
                    $pattern = self::likePattern((string) $filters['q']);

                    return $q->where(function (Builder $match) use ($pattern): void {
                        $match->whereRaw(self::likeSql('projects.name'), [$pattern])
                            ->orWhereRaw(self::likeSql('projects.key'), [$pattern]);
                    });
                },
            )
            ->orderBy($column, $direction)
            ->orderBy('id');

        return ApiResponse::paginated($this->paginate($request, $query), ProjectResource::class);
    }

    public function show(Request $request, string $project): JsonResponse
    {
        $record = $this->findInWorkspace($request, Project::class, $project, ['status', 'owner', 'manager']);

        $this->authorize('view', $record);

        return ApiResponse::item(new ProjectResource($record));
    }

    public function store(StoreProjectRequest $request, CreateProject $create): JsonResponse
    {
        $workspace = $this->workspace($request);
        $actor = $this->actor($request);

        $this->authorize('create', [Project::class, $workspace]);

        // Money is a separate permission from creating the project it hangs off, so the
        // budget fields are dropped rather than refused: a caller without `budget.manage`
        // still gets their project.
        $project = $create(
            $workspace,
            $actor,
            $request->toAttributes($actor->can(Permission::BudgetManage->value, $workspace)),
        );

        $project->load(['status', 'owner', 'manager']);

        return ApiResponse::item(new ProjectResource($project), 201);
    }

    public function update(UpdateProjectRequest $request, string $project, UpdateProject $update): JsonResponse
    {
        $record = $this->findInWorkspace($request, Project::class, $project);
        $actor = $this->actor($request);

        $this->authorize('update', $record);

        $updated = $update($record, $request->toAttributes($actor->can('manageBudget', $record)), $actor);

        $updated->load(['status', 'owner', 'manager']);

        return ApiResponse::item(new ProjectResource($updated));
    }

    public function destroy(Request $request, string $project, DeleteProject $delete): JsonResponse
    {
        $record = $this->findInWorkspace($request, Project::class, $project);

        $this->authorize('delete', $record);

        $delete($record, $this->actor($request));

        return ApiResponse::deleted();
    }
}
