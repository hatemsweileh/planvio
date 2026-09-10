<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskMoved;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\PositionExhausted;
use App\Services\TaskOrderingService;
use Illuminate\Support\Facades\DB;

/**
 * The Kanban drop: puts a task in a column, between two neighbours.
 *
 * The client sends the two cards the task was dropped between, never an index. An index
 * would be a claim about the whole column — "I am now the fourth card" — computed from a
 * board that may be seconds out of date or simply made up, and honouring it would reshuffle
 * cards the person never touched. Anchors are a claim about two rows, and both are verified
 * against the database before anything is written:
 *
 *   - an anchor that is not a live card in the target column is discarded;
 *   - the opposite neighbour is looked up from the anchor rather than trusted, so a stale
 *     board still lands the card immediately under the card it was dropped under;
 *   - with no usable anchor at all the task goes to the end of the column.
 *
 * The midpoint itself, and the renumbering that catches it when the decimals run out, belong
 * to {@see TaskOrderingService} — the same arithmetic every other ordering path uses.
 */
final class MoveTask
{
    public function __construct(
        private readonly ChangeTaskStatus $changeStatus,
        private readonly TaskOrderingService $ordering,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(
        Task $task,
        TaskStatus $status,
        ?int $beforeTaskId,
        ?int $afterTaskId,
        User $actor,
    ): Task {
        if ((int) $status->project_id !== (int) $task->project_id) {
            throw new TaskStatusNotInProject($status, (int) $task->project_id);
        }

        $taskId = (int) $task->getKey();
        $fromStatusId = (int) $task->status_id;
        $toStatusId = (int) $status->getKey();
        $fromPosition = (string) $task->position;

        // A card cannot be its own neighbour; a client that says so is describing the board
        // it drew before the drop, not the one it wants.
        $beforeTaskId = $beforeTaskId === $taskId ? null : $beforeTaskId;
        $afterTaskId = $afterTaskId === $taskId ? null : $afterTaskId;

        DB::transaction(function () use (
            $task,
            $status,
            $actor,
            $taskId,
            $fromStatusId,
            $fromPosition,
            $toStatusId,
            $beforeTaskId,
            $afterTaskId,
        ): void {
            if ($fromStatusId !== $toStatusId) {
                ($this->changeStatus)($task, $status, $actor);
            }

            $position = $this->resolve($toStatusId, $taskId, $beforeTaskId, $afterTaskId);

            if ($position === null) {
                // The gap ran out of decimals. Renumbering rewrites the whole column, so the
                // anchors have new positions and have to be read again before splitting.
                $this->ordering->renormalise($toStatusId);

                $position = $this->resolve($toStatusId, $taskId, $beforeTaskId, $afterTaskId)
                    ?? $this->ordering->nextPosition($toStatusId);
            }

            $task->position = $position;
            $task->save();

            $this->activity->record($task, 'moved', $actor, [
                'from_status_id' => $fromStatusId,
                'to_status_id' => $toStatusId,
                'old' => $fromPosition,
                'new' => (string) $task->position,
            ]);
        });

        event(new TaskMoved(
            $task,
            $fromStatusId,
            $toStatusId,
            $fromPosition,
            (string) $task->position,
            $actor,
        ));

        return $task;
    }

    /**
     * The position between the two verified neighbours, or null when the column has to be
     * renumbered before any value will fit.
     */
    private function resolve(int $statusId, int $taskId, ?int $beforeTaskId, ?int $afterTaskId): ?float
    {
        $before = $beforeTaskId === null ? null : $this->positionInColumn($beforeTaskId, $statusId);
        $after = $afterTaskId === null ? null : $this->positionInColumn($afterTaskId, $statusId);

        try {
            if ($before !== null) {
                // The card below the anchor is whatever the database says it is, not whatever
                // the client believed it was.
                return $this->ordering->positionBetween(
                    $before,
                    $this->neighbourBelow($statusId, $before, $taskId),
                );
            }

            if ($after !== null) {
                return $this->ordering->positionBetween(
                    $this->neighbourAbove($statusId, $after, $taskId),
                    $after,
                );
            }

            return $this->ordering->nextPosition($statusId);
        } catch (PositionExhausted) {
            return null;
        }
    }

    /**
     * The stored position of a task, but only when it really sits in the given column.
     *
     * A `task_statuses` row belongs to exactly one project, so filtering on `status_id` is
     * enough to keep this inside the board — and inside the tenant — on its own.
     */
    private function positionInColumn(int $taskId, int $statusId): ?float
    {
        $position = Task::query()
            ->whereKey($taskId)
            ->where('status_id', $statusId)
            ->value('position');

        return $position === null ? null : (float) $position;
    }

    private function neighbourBelow(int $statusId, float $position, int $excludeTaskId): ?float
    {
        $value = Task::query()
            ->where('status_id', $statusId)
            ->whereKeyNot($excludeTaskId)
            ->where('position', '>', $position)
            ->orderBy('position')
            ->orderBy('id')
            ->value('position');

        return $value === null ? null : (float) $value;
    }

    private function neighbourAbove(int $statusId, float $position, int $excludeTaskId): ?float
    {
        $value = Task::query()
            ->where('status_id', $statusId)
            ->whereKeyNot($excludeTaskId)
            ->where('position', '<', $position)
            ->orderByDesc('position')
            ->orderByDesc('id')
            ->value('position');

        return $value === null ? null : (float) $value;
    }
}
