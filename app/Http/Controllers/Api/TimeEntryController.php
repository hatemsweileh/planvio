<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Time\DeleteTimeEntry;
use App\Actions\Time\LogTime;
use App\Actions\Time\UpdateTimeEntry;
use App\Http\Requests\Api\StoreTimeEntryRequest;
use App\Http\Requests\Api\UpdateTimeEntryRequest;
use App\Http\Resources\ApiResponse;
use App\Http\Resources\TimeEntryResource;
use App\Models\Project;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Policies\TimeEntryPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logged time.
 *
 * ## Whose time you can see
 *
 * `time.view_all` is the permission for reading somebody else's hours, and the matrix grants
 * it to owners and admins outright and to a manager only inside projects they manage. So the
 * list is narrowed to the caller's own entries unless they hold it — asked through
 * {@see TimeEntryPolicy::viewAll()}, the same question the timesheet screen
 * asks. A `user_id` filter is honoured only within that: a member who asks for a colleague's
 * entries gets an empty page rather than a refusal, because the honest answer to "show me
 * their time" is that there is nothing here for you.
 *
 * Time is always logged *as the caller*. There is no `user_id` on the write: a token that
 * could file hours against somebody else's name would make every timesheet unreliable, and
 * the product has no screen that does it either.
 */
final class TimeEntryController extends ApiController
{
    /**
     * @var array<string, string>
     */
    private const SORTS = [
        'spent_on' => 'spent_on',
        'minutes' => 'minutes',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request);
        $user = $this->actor($request);

        $this->authorize('viewAny', TimeEntry::class);

        $filters = $request->validate([
            'project_id' => ['nullable', 'integer', 'min:1'],
            'task_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'billable' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$column, $direction] = $this->sort($request, self::SORTS, 'spent_on');

        /*
         | Whether the caller may read other people's hours, asked about the project they are
         | asking about. It matters: the matrix gives a manager `time.view_all` only inside
         | projects they manage, so asked with no project at all the answer for them is no —
         | and a manager filtering to a project they run would otherwise see only themselves.
         */
        $scope = isset($filters['project_id'])
            ? $this->findInWorkspace($request, Project::class, $filters['project_id'])
            : null;

        $seesEveryone = $user->can('viewAll', [TimeEntry::class, $scope]);

        $query = TimeEntry::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $workspace->getKey())
            ->with('user')
            ->unless($seesEveryone, fn (Builder $q): Builder => $q->where('user_id', $user->getKey()))
            ->when(isset($filters['user_id']) && $seesEveryone, fn (Builder $q): Builder => $q->where('user_id', $filters['user_id']))
            ->when(isset($filters['project_id']), fn (Builder $q): Builder => $q->where('project_id', $filters['project_id']))
            ->when(isset($filters['task_id']), fn (Builder $q): Builder => $q->where('task_id', $filters['task_id']))
            ->when(isset($filters['from']), fn (Builder $q): Builder => $q->where('spent_on', '>=', (string) $filters['from']))
            ->when(isset($filters['to']), fn (Builder $q): Builder => $q->where('spent_on', '<=', (string) $filters['to']))
            ->when(
                array_key_exists('billable', $filters) && $filters['billable'] !== null,
                fn (Builder $q): Builder => $q->where('is_billable', $request->boolean('billable')),
            )
            ->orderBy($column, $direction)
            ->orderBy('id');

        // A filter for somebody else's time, from somebody who may not read it, silently
        // narrows to nothing rather than quietly widening to their own.
        if (isset($filters['user_id']) && ! $seesEveryone && (int) $filters['user_id'] !== (int) $user->getKey()) {
            $query->whereRaw('1 = 0');
        }

        return ApiResponse::paginated($this->paginate($request, $query), TimeEntryResource::class);
    }

    public function show(Request $request, string $entry): JsonResponse
    {
        $record = $this->findInWorkspace($request, TimeEntry::class, $entry, ['user']);

        $this->authorize('view', $record);

        return ApiResponse::item(new TimeEntryResource($record));
    }

    public function store(StoreTimeEntryRequest $request, LogTime $log): JsonResponse
    {
        $actor = $this->actor($request);
        $project = $this->findInWorkspace($request, Project::class, $request->projectId());

        $this->authorize('create', [TimeEntry::class, $project]);

        $task = null;
        $taskId = $request->taskId();

        if ($taskId !== null) {
            $task = $this->findInWorkspace($request, Task::class, $taskId);

            abort_if(
                (int) $task->project_id !== (int) $project->getKey(),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                __('That task is not in the project the time is being logged against.'),
            );
        }

        $entry = $log(
            $actor,
            $project,
            $request->minutes(),
            $request->date('spent_on'),
            $task,
            $request->description(),
            $request->isBillable(),
        );

        $entry->load('user');

        return ApiResponse::item(new TimeEntryResource($entry), 201);
    }

    public function update(UpdateTimeEntryRequest $request, string $entry, UpdateTimeEntry $update): JsonResponse
    {
        $record = $this->findInWorkspace($request, TimeEntry::class, $entry);

        $this->authorize('update', $record);

        // The action replaces the entry rather than patching it, so anything the caller did
        // not mention is filled in from the row as it stands.
        $task = $record->task_id === null
            ? null
            : $this->findInWorkspace($request, Task::class, (int) $record->task_id);

        if ($request->touches('task_id')) {
            $taskId = $request->taskId();
            $task = $taskId === null ? null : $this->findInWorkspace($request, Task::class, $taskId);

            abort_if(
                $task !== null && (int) $task->project_id !== (int) $record->project_id,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                __('That task is not in the project the time was logged against.'),
            );
        }

        $updated = $update(
            $record,
            $this->actor($request),
            $request->touches('minutes') ? $request->integer('minutes') : (int) $record->minutes,
            $request->touches('spent_on') ? $request->date('spent_on') : $record->spent_on,
            $request->touches('description') ? $request->description() : $record->description,
            $request->touches('is_billable') ? $request->boolean('is_billable') : (bool) $record->is_billable,
            $task,
        );

        $updated->load('user');

        return ApiResponse::item(new TimeEntryResource($updated));
    }

    public function destroy(Request $request, string $entry, DeleteTimeEntry $delete): JsonResponse
    {
        $record = $this->findInWorkspace($request, TimeEntry::class, $entry);

        $this->authorize('delete', $record);

        $delete($record, $this->actor($request));

        return ApiResponse::deleted();
    }
}
