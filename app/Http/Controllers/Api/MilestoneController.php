<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Milestones\CreateMilestone;
use App\Actions\Milestones\DeleteMilestone;
use App\Actions\Milestones\UpdateMilestone;
use App\Enums\MilestoneStatus;
use App\Http\Requests\Api\StoreMilestoneRequest;
use App\Http\Requests\Api\UpdateMilestoneRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\MilestoneResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Milestones.
 *
 * Ordered by due date with the undated last, because a milestone list is a schedule and a
 * schedule reads forwards. `position` is available as a sort for callers that mirror the
 * manual ordering the timeline screen uses.
 */
final class MilestoneController extends ApiController
{
    /**
     * @var array<string, string>
     */
    private const SORTS = [
        'due_date' => 'due_date',
        'position' => 'position',
        'name' => 'name',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $this->actor($request);

        $this->authorize('viewAny', Milestone::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::enum(MilestoneStatus::class)],
            'open' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$column, $direction] = $this->sort($request, self::SORTS, 'due_date');

        $visibleProjects = Project::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->select('projects.id')
            ->where('projects.workspace_id', $workspace->getKey())
            ->visibleTo($user);

        $query = Milestone::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('milestones.workspace_id', $workspace->getKey())
            ->whereIn('milestones.project_id', $visibleProjects)
            ->when(isset($filters['project_id']), fn (Builder $q): Builder => $q->where('milestones.project_id', $filters['project_id']))
            ->when(isset($filters['status']), fn (Builder $q): Builder => $q->where('milestones.status', $filters['status']))
            ->when($request->boolean('open'), fn (Builder $q): Builder => $q->open())
            // Undated milestones sort last whichever direction is asked for: "no date" is not
            // a very early date, and a null that sorted first would put the unplanned work at
            // the top of every schedule.
            ->orderByRaw('case when milestones.due_date is null then 1 else 0 end')
            ->orderBy($column, $direction)
            ->orderBy('milestones.id');

        return ApiResponse::paginated($this->paginate($request, $query), MilestoneResource::class);
    }

    public function show(Request $request, string $milestone): JsonResponse
    {
        $record = $this->findInWorkspace($request, Milestone::class, $milestone, ['owner']);

        $this->authorize('view', $record);

        return ApiResponse::item(new MilestoneResource($record));
    }

    public function store(StoreMilestoneRequest $request, CreateMilestone $create): JsonResponse
    {
        $project = $this->findInWorkspace($request, Project::class, $request->projectId());

        $this->authorize('create', [Milestone::class, $project]);

        $ownerId = $request->ownerId();

        if ($ownerId !== null) {
            // Resolved rather than trusted: an owner id from another tenant would otherwise
            // be written straight into the column.
            $this->workspaceMember($request, $ownerId);
        }

        $milestone = $create($project, $request->toAttributes(), $this->actor($request));
        $milestone->load('owner');

        return ApiResponse::item(new MilestoneResource($milestone), 201);
    }

    public function update(UpdateMilestoneRequest $request, string $milestone, UpdateMilestone $update): JsonResponse
    {
        $record = $this->findInWorkspace($request, Milestone::class, $milestone);

        $this->authorize('update', $record);

        if ($request->mentionsOwner() && $request->ownerId() !== null) {
            $this->workspaceMember($request, (int) $request->ownerId());
        }

        $updated = $update($record, $request->toAttributes(), $this->actor($request));
        $updated->load('owner');

        return ApiResponse::item(new MilestoneResource($updated));
    }

    public function destroy(Request $request, string $milestone, DeleteMilestone $delete): JsonResponse
    {
        $record = $this->findInWorkspace($request, Milestone::class, $milestone);

        $this->authorize('delete', $record);

        $delete($record, $this->actor($request));

        return ApiResponse::deleted();
    }
}
