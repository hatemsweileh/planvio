<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Events\Tasks\TaskUpdated;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a partial edit to a task.
 *
 * Status and assignee are delegated to {@see ChangeTaskStatus} and {@see AssignTask} rather
 * than written here. Both carry consequences beyond the column — completion stamps, watcher
 * notifications, their own activity events — and duplicating those rules in a second place
 * is how the two paths drift apart.
 *
 * Only columns whose value actually differs are written, so re-submitting an unchanged form
 * touches nothing and leaves no entry in the feed.
 */
final class UpdateTask
{
    public function __construct(
        private readonly ChangeTaskStatus $changeStatus,
        private readonly AssignTask $assign,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(Task $task, TaskChanges $changes, User $actor): Task
    {
        if ($changes->isEmpty()) {
            return $task;
        }

        $this->assertValid($task, $changes);

        return DB::transaction(function () use ($task, $changes, $actor): Task {
            if ($changes->status !== null) {
                ($this->changeStatus)($task, $changes->status, $actor);
            }

            if ($changes->touches('assignee_id')) {
                ($this->assign)($task, $changes->assignee, $actor);
            }

            $diff = $this->diff($task, $changes->plainColumns());

            if ($diff === []) {
                return $task;
            }

            foreach ($changes->plainColumns() as $column => $value) {
                if (array_key_exists($column, $diff)) {
                    $task->setAttribute($column, $value);
                }
            }

            $task->save();

            $this->activity->record($task, 'updated', $actor, ['changes' => $diff]);

            event(new TaskUpdated($task, $diff, $actor));

            return $task;
        });
    }

    /**
     * The subset of $columns that would really change something.
     *
     * @param array<string, mixed> $columns
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function diff(Task $task, array $columns): array
    {
        $diff = [];

        foreach ($columns as $column => $value) {
            $old = $this->comparable($column, $task->getAttribute($column));
            $new = $this->comparable($column, $value);

            if ($old !== $new) {
                $diff[$column] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    /**
     * Reduce a value to something two versions of it can be compared by identity.
     *
     * Casts make the stored side an enum, a Carbon or an int while the incoming side is
     * still a raw value, so a plain `!==` would report every field as changed.
     */
    private function comparable(string $column, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }

        if (str_ends_with($column, '_id') || in_array($column, ['estimate_minutes', 'progress'], true)) {
            return (int) $value;
        }

        return $value;
    }

    private function assertValid(Task $task, TaskChanges $changes): void
    {
        $columns = $changes->attributes;

        if ($changes->milestone !== null && (int) $changes->milestone->project_id !== (int) $task->project_id) {
            throw new MilestoneNotInProject($changes->milestone, (int) $task->project_id);
        }

        if (array_key_exists('progress', $columns)) {
            $progress = (int) $columns['progress'];

            if ($progress < 0 || $progress > 100) {
                throw InvalidTaskAttributes::progressOutOfRange($progress);
            }
        }

        if (array_key_exists('estimate_minutes', $columns) && $columns['estimate_minutes'] !== null
            && (int) $columns['estimate_minutes'] < 0) {
            throw InvalidTaskAttributes::negativeEstimate((int) $columns['estimate_minutes']);
        }

        // Either date may be the one being edited, so the rule is checked against the state
        // the task will be in once the change lands, not against the state it is in now.
        $start = array_key_exists('start_date', $columns)
            ? $columns['start_date']
            : $task->start_date?->toDateString();

        $due = array_key_exists('due_date', $columns)
            ? $columns['due_date']
            : $task->due_date?->toDateString();

        if (is_string($start) && is_string($due) && $due < $start) {
            throw InvalidTaskAttributes::dueBeforeStart();
        }
    }
}
