<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskCreated;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TaskWatcher;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PositionExhausted;
use App\Services\TaskOrderingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Puts a new task on a project board.
 *
 * Calling this twice with the same data creates two tasks, which is the intent: two people
 * asking for "Fix the footer" mean two pieces of work. What must never happen twice is the
 * *number* — see {@see TaskNumbers} for why that allocation is locked.
 */
final class CreateTask
{
    public function __construct(
        private readonly TaskNumbers $numbers,
        private readonly TaskOrderingService $ordering,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(CreateTaskData $data): Task
    {
        $project = $data->project;
        $projectId = (int) $project->getKey();
        $workspaceId = (int) $project->workspace_id;

        $status = $this->resolveStatus($data, $project);
        $reporter = $data->reporter ?? $data->actor;

        $this->assertReferencesBelongHere($data, $projectId, $workspaceId);

        $task = DB::transaction(function () use ($data, $projectId, $workspaceId, $status, $reporter): Task {
            $closed = $status->is_completed || $status->category->isClosed();

            $task = Task::query()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                // Allocated here, inside the transaction that inserts the row, so the lock
                // taken on the project is still held when the number is consumed.
                'number' => $this->numbers->allocate($projectId),
                'title' => $data->title,
                'description' => $data->description,
                'status_id' => $status->getKey(),
                'priority' => $data->priority,
                'assignee_id' => $data->assignee?->getKey(),
                'reporter_id' => $reporter->getKey(),
                'parent_id' => $data->parent?->getKey(),
                'milestone_id' => $data->milestone?->getKey(),
                'start_date' => $data->startDate === null ? null : Carbon::instance($data->startDate)->toDateString(),
                'due_date' => $data->dueDate === null ? null : Carbon::instance($data->dueDate)->toDateString(),
                'completed_at' => $closed ? Carbon::now() : null,
                'estimate_minutes' => $data->estimateMinutes,
                'position' => $data->position ?? $this->append((int) $status->getKey()),
                'progress' => $closed && $status->category->isCompleted() ? 100 : 0,
                'recurring_task_id' => $data->recurringTask?->getKey(),
                'created_by' => $data->actor->getKey(),
                'ai_generated' => $data->aiGenerated,
            ]);

            // Whoever raised the task, and whoever has to do it, follow it from the start.
            // Without this the watcher list is empty until somebody opts in by hand, and
            // the notifications the product promises never reach anyone.
            $this->watch($task, $reporter);

            if ($data->assignee !== null) {
                $this->watch($task, $data->assignee);
            }

            $this->activity->record($task, 'created', $data->actor, [
                'title' => $task->title,
                'status_id' => (int) $status->getKey(),
                'status' => $status->name,
                'priority' => $task->priority->value,
                'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
                'parent_id' => $task->parent_id === null ? null : (int) $task->parent_id,
            ]);

            return $task;
        });

        $task->setRelation('project', $project);
        $task->setRelation('status', $status);

        event(new TaskCreated($task, $data->actor));

        return $task;
    }

    /**
     * The column the task lands in: the one asked for, the project default, or the first
     * open column on the board.
     */
    private function resolveStatus(CreateTaskData $data, Project $project): TaskStatus
    {
        if ($data->status !== null) {
            if ((int) $data->status->project_id !== (int) $project->getKey()) {
                throw new TaskStatusNotInProject($data->status, $project);
            }

            return $data->status;
        }

        $status = TaskStatus::query()
            ->forProject($project)
            ->orderByDesc('is_default')
            ->orderBy('is_completed')
            ->orderBy('position')
            ->orderBy('id')
            ->first();

        if (! $status instanceof TaskStatus) {
            throw new NoTaskStatusAvailable($project);
        }

        return $status;
    }

    private function assertReferencesBelongHere(CreateTaskData $data, int $projectId, int $workspaceId): void
    {
        if ($data->milestone instanceof Milestone && (int) $data->milestone->project_id !== $projectId) {
            throw new MilestoneNotInProject($data->milestone, $projectId);
        }

        if ($data->parent instanceof Task && (int) $data->parent->project_id !== $projectId) {
            throw new ParentTaskNotInProject($data->parent, $projectId);
        }

        if ($data->startDate !== null && $data->dueDate !== null
            && Carbon::instance($data->dueDate)->startOfDay()->isBefore(Carbon::instance($data->startDate)->startOfDay())) {
            throw InvalidTaskAttributes::dueBeforeStart();
        }

        if ($data->estimateMinutes !== null && $data->estimateMinutes < 0) {
            throw InvalidTaskAttributes::negativeEstimate($data->estimateMinutes);
        }

        if ($data->assignee instanceof User && ! $data->assignee->memberOf($data->project->workspace)) {
            throw new AssigneeNotInWorkspace($workspaceId, $data->assignee);
        }
    }

    /**
     * The end of a column, renumbering it first if the append would run past the range the
     * ordering can represent. A column only reaches that after roughly a million appends, so
     * the retry is a safety net rather than a path anyone travels.
     */
    private function append(int $statusId): float
    {
        try {
            return $this->ordering->nextPosition($statusId);
        } catch (PositionExhausted) {
            $this->ordering->renormalise($statusId);

            return $this->ordering->nextPosition($statusId);
        }
    }

    private function watch(Task $task, User $user): void
    {
        TaskWatcher::query()->firstOrCreate([
            'task_id' => $task->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }
}
