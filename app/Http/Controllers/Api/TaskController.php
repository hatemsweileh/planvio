<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\AssignTask;
use App\Actions\Tasks\ChangeTaskStatus;
use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
use App\Actions\Tasks\DeleteTask;
use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\UpdateTask;
use App\Enums\Priority;
use App\Http\Requests\Api\AssignTaskRequest;
use App\Http\Requests\Api\ChangeTaskStatusRequest;
use App\Http\Requests\Api\StoreTaskRequest;
use App\Http\Requests\Api\UpdateTaskRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\TaskResource;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Policies\TaskPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tasks.
 *
 * ## Two gates, not one
 *
 * The list is narrowed to tasks in projects the caller can see — the same
 * {@see Project::scopeVisibleTo()} subquery the project list uses — *and* every single
 * record is then run through {@see TaskPolicy}. The narrowing is what keeps a
 * guest from learning how many tasks exist; the policy is what actually decides. Neither one
 * on its own is enough (ARCHITECTURE.md §3).
 *
 * ## Why status changes are a separate endpoint
 *
 * `PATCH /tasks/{task}` cannot move a task between columns. Moving one has consequences —
 * `completed_at`, watcher notifications, the `task.status_changed` webhook, the activity
 * entry a person reads — and {@see ChangeTaskStatus} owns all of them. A second path that
 * wrote `status_id` directly would be a second path that forgot one of them, and the two
 * would drift. The same argument makes assignment its own endpoint: it has its own
 * permission (`task.assign`) and its own notification.
 */
final class TaskController extends ApiController
{
    /**
     * @var array<string, string>
     */
    private const SORTS = [
        'created_at' => 'tasks.created_at',
        'updated_at' => 'tasks.updated_at',
        'due_date' => 'tasks.due_date',
        'priority' => 'tasks.priority',
        'title' => 'tasks.title',
        'number' => 'tasks.number',
    ];

    /** Loaded on every render so `key` is "WEB-42" rather than "#42". */
    private const RELATIONS = ['project', 'status', 'assignee'];

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $this->actor($request);

        // No project argument: this asks "could this role see tasks anywhere in the bound
        // workspace". Which tasks they may actually see is settled row by row below.
        $this->authorize('viewAny', Task::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
            'status_id' => ['nullable', 'integer', 'min:1'],
            'milestone_id' => ['nullable', 'integer', 'min:1'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'completed' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'due_before' => ['nullable', 'date'],
            'due_after' => ['nullable', 'date'],
            'q' => ['nullable', 'string', 'max:128'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$column, $direction] = $this->sort($request, self::SORTS, 'updated_at');

        $visibleProjects = Project::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->select('projects.id')
            ->where('projects.workspace_id', $workspace->getKey())
            ->visibleTo($user);

        $query = Task::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('tasks.workspace_id', $workspace->getKey())
            ->whereIn('tasks.project_id', $visibleProjects)
            ->with(self::RELATIONS)
            ->when(isset($filters['project_id']), fn (Builder $q): Builder => $q->where('tasks.project_id', $filters['project_id']))
            ->when(isset($filters['assignee_id']), fn (Builder $q): Builder => $q->where('tasks.assignee_id', $filters['assignee_id']))
            ->when(isset($filters['status_id']), fn (Builder $q): Builder => $q->where('tasks.status_id', $filters['status_id']))
            ->when(isset($filters['milestone_id']), fn (Builder $q): Builder => $q->where('tasks.milestone_id', $filters['milestone_id']))
            ->when(isset($filters['priority']), fn (Builder $q): Builder => $q->where('tasks.priority', $filters['priority']))
            ->when(array_key_exists('completed', $filters) && $filters['completed'] !== null, fn (Builder $q): Builder => $request->boolean('completed')
                ? $q->whereNotNull('tasks.completed_at')
                : $q->whereNull('tasks.completed_at'))
            ->when($request->boolean('overdue'), fn (Builder $q): Builder => $q->overdue())
            ->when(isset($filters['due_before']), fn (Builder $q): Builder => $q->whereNotNull('tasks.due_date')
                ->where('tasks.due_date', '<=', (string) $filters['due_before']))
            ->when(isset($filters['due_after']), fn (Builder $q): Builder => $q->whereNotNull('tasks.due_date')
                ->where('tasks.due_date', '>=', (string) $filters['due_after']))
            ->when(
                isset($filters['q']) && trim((string) $filters['q']) !== '',
                fn (Builder $q): Builder => $q->whereRaw(self::likeSql('tasks.title'), [self::likePattern((string) $filters['q'])]),
            )
            ->orderBy($column, $direction)
            ->orderBy('tasks.id');

        return ApiResponse::paginated($this->paginate($request, $query), TaskResource::class);
    }

    public function show(Request $request, string $task): JsonResponse
    {
        $record = $this->findInWorkspace($request, Task::class, $task, [...self::RELATIONS, 'tags']);

        $this->authorize('view', $record);

        return ApiResponse::item(new TaskResource($record));
    }

    public function store(StoreTaskRequest $request, CreateTask $create): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findInWorkspace($request, Project::class, $request->projectId());

        $this->authorize('create', [Task::class, $project]);

        $data = new CreateTaskData(
            project: $project,
            actor: $actor,
            title: $request->title(),
            description: $request->description(),
            status: $this->column($request, $request->statusId()),
            priority: $request->priority(),
            assignee: $this->assignee($request, $request->assigneeId()),
            reporter: $actor,
            parent: $this->parent($request, $request->parentId()),
            milestone: $this->milestone($request, $request->milestoneId()),
            startDate: $request->date('start_date'),
            dueDate: $request->date('due_date'),
            estimateMinutes: $request->estimateMinutes(),
        );

        $task = $create($data);
        $task->load(self::RELATIONS);

        return ApiResponse::item(new TaskResource($task), 201);
    }

    public function update(UpdateTaskRequest $request, string $task, UpdateTask $update): JsonResponse
    {
        $record = $this->findInWorkspace($request, Task::class, $task);
        $actor = $this->actor($request);

        $this->authorize('update', $record);

        $changes = TaskChanges::make();

        if ($request->touches('title')) {
            $changes = $changes->title($request->title());
        }

        if ($request->touches('description')) {
            $changes = $changes->description($request->description());
        }

        if ($request->touches('priority')) {
            $priority = $request->enum('priority', Priority::class);
            $changes = $priority instanceof Priority ? $changes->priority($priority) : $changes;
        }

        if ($request->touches('assignee_id')) {
            // Reassignment carries its own permission, so an edit that also moves the task to
            // somebody else has to satisfy both. Splitting this would let `task.update` do
            // what `task.assign` exists to gate.
            $this->authorize('assign', $record);
            $changes = $changes->assignee($this->assignee($request, $request->assigneeId()));
        }

        if ($request->touches('milestone_id')) {
            $changes = $changes->milestone($this->milestone($request, $request->milestoneId()));
        }

        if ($request->touches('start_date')) {
            $changes = $changes->startDate($request->date('start_date'));
        }

        if ($request->touches('due_date')) {
            $changes = $changes->dueDate($request->date('due_date'));
        }

        if ($request->touches('estimate_minutes')) {
            $changes = $changes->estimateMinutes($request->estimateMinutes());
        }

        if ($request->touches('progress')) {
            $changes = $changes->progress($request->integer('progress'));
        }

        $updated = $update($record, $changes, $actor);
        $updated->load(self::RELATIONS);

        return ApiResponse::item(new TaskResource($updated));
    }

    public function destroy(Request $request, string $task, DeleteTask $delete): JsonResponse
    {
        $record = $this->findInWorkspace($request, Task::class, $task);

        $this->authorize('delete', $record);

        $delete($record, $this->actor($request));

        return ApiResponse::deleted();
    }

    /**
     * `POST /tasks/{task}/assign` — `{"assignee_id": 7}`, or `null` to unassign.
     */
    public function assign(AssignTaskRequest $request, string $task, AssignTask $assign): JsonResponse
    {
        $record = $this->findInWorkspace($request, Task::class, $task);

        $this->authorize('assign', $record);

        $updated = $assign($record, $this->assignee($request, $request->assigneeId()), $this->actor($request));
        $updated->load(self::RELATIONS);

        return ApiResponse::item(new TaskResource($updated));
    }

    /**
     * `POST /tasks/{task}/status` — `{"status_id": 12}`.
     *
     * The status is looked up inside the workspace here and inside the *project* by the
     * action, which is what makes a column from another board a refusal rather than a task
     * that silently lands somewhere it cannot be seen.
     */
    public function status(
        ChangeTaskStatusRequest $request,
        string $task,
        ChangeTaskStatus $changeStatus,
    ): JsonResponse {
        $record = $this->findInWorkspace($request, Task::class, $task);

        $this->authorize('changeStatus', $record);

        $status = $this->findInWorkspace($request, TaskStatus::class, $request->statusId());

        $updated = $changeStatus($record, $status, $this->actor($request));
        $updated->load(self::RELATIONS);

        return ApiResponse::item(new TaskResource($updated));
    }

    /* ------------------------------------------------------------------ *
     * Reference resolution
     *
     * Each of these turns an id from the request body into a record that is
     * provably in this workspace, or into a 404. An id that resolved to a
     * foreign record here would be a cross-tenant write that every later
     * check waves through, because by then it is holding a model.
     * ------------------------------------------------------------------ */

    private function assignee(Request $request, ?int $userId): ?User
    {
        return $userId === null ? null : $this->workspaceMember($request, $userId);
    }

    private function column(Request $request, ?int $statusId): ?TaskStatus
    {
        return $statusId === null ? null : $this->findInWorkspace($request, TaskStatus::class, $statusId);
    }

    private function milestone(Request $request, ?int $milestoneId): ?Milestone
    {
        return $milestoneId === null ? null : $this->findInWorkspace($request, Milestone::class, $milestoneId);
    }

    private function parent(Request $request, ?int $taskId): ?Task
    {
        return $taskId === null ? null : $this->findInWorkspace($request, Task::class, $taskId);
    }
}
