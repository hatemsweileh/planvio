<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use BackedEnum;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Applies the same edit to many tasks.
 *
 * Three properties shape this action:
 *
 *   - **One statement per chunk.** Loading five hundred tasks as models, editing each and
 *     saving it is a thousand queries and five hundred hydrated objects. Here each chunk is
 *     read as the few columns the diff needs, written with a single UPDATE, and recorded
 *     with a single INSERT.
 *   - **One activity row per task.** A single "500 tasks changed" entry cannot be filtered
 *     by task, attributed, or undone. The feed keeps its granularity even when the edit did
 *     not.
 *   - **Bounded memory.** Ids arrive as an iterable and are consumed lazily, so the caller
 *     may hand over a cursor; nothing larger than one chunk is ever held.
 *
 * Tasks whose values already match are skipped, so re-running the same bulk edit writes
 * nothing the second time.
 *
 * One thing this deliberately does not do is raise a per-task domain event or notify the
 * watchers of every task it touches. Moving five hundred cards into Done in one gesture is a
 * single decision, and turning it into five hundred notifications would bury the ones that
 * matter. Callers that want the full ceremony of a single move loop over
 * {@see ChangeTaskStatus} instead.
 */
final class BulkUpdateTasks
{
    private const CHUNK = 200;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param iterable<int, int|string> $taskIds
     * @return int the number of tasks actually changed
     */
    public function __invoke(iterable $taskIds, TaskChanges $changes, User $actor, int $chunkSize = self::CHUNK): int
    {
        if ($changes->isEmpty()) {
            return 0;
        }

        $this->assertValid($changes);

        $changed = 0;

        LazyCollection::make(static function () use ($taskIds): iterable {
            foreach ($taskIds as $id) {
                yield (int) $id;
            }
        })
            ->chunk(max(1, $chunkSize))
            ->each(function (LazyCollection $chunk) use ($changes, $actor, &$changed): void {
                $changed += $this->applyChunk($chunk->values()->all(), $changes, $actor);
            });

        return $changed;
    }

    /**
     * @param list<int> $ids
     */
    private function applyChunk(array $ids, TaskChanges $changes, User $actor): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $changes, $actor): int {
            $tasks = Task::query()
                ->whereIn('id', $ids)
                ->get($this->selection($changes))
                ->all();

            /** @var list<Task> $subjects */
            $subjects = [];
            /** @var array<int, array<string, array{old: mixed, new: mixed}>> $diffs */
            $diffs = [];
            /** @var list<int> $moving tasks whose column actually changes */
            $moving = [];
            /** @var list<int> $staying */
            $staying = [];

            foreach ($tasks as $task) {
                if ($changes->status !== null && (int) $changes->status->project_id !== (int) $task->project_id) {
                    throw new TaskStatusNotInProject($changes->status, (int) $task->project_id);
                }

                $diff = $this->diff($task, $changes->attributes);

                if ($diff === []) {
                    continue;
                }

                $id = (int) $task->getKey();
                $subjects[] = $task;
                $diffs[$id] = $diff;

                if (array_key_exists('status_id', $diff)) {
                    $moving[] = $id;
                } else {
                    $staying[] = $id;
                }
            }

            if ($subjects === []) {
                return 0;
            }

            $columns = $this->columns($changes);

            // Completion is derived from the column a task lands in, so it may only be
            // rewritten for the tasks that actually moved. Stamping it across the batch would
            // reset the completion date of every task that was already sitting in that column.
            if ($moving !== []) {
                Task::query()->whereIn('id', $moving)->update($columns + $this->completion($changes));
            }

            if ($staying !== []) {
                Task::query()->whereIn('id', $staying)->update($columns);
            }

            $this->activity->recordEach(
                $subjects,
                'updated',
                $actor,
                static fn (Task $task): array => ['bulk' => true, 'changes' => $diffs[(int) $task->getKey()]],
            );

            return count($subjects);
        });
    }

    /**
     * The columns the diff needs, and nothing else.
     *
     * @return list<string>
     */
    private function selection(TaskChanges $changes): array
    {
        return array_values(array_unique([
            'id',
            'workspace_id',
            'project_id',
            'status_id',
            ...array_keys($changes->attributes),
        ]));
    }

    /**
     * The touched columns, reduced to values a query builder can bind.
     *
     * @return array<string, mixed>
     */
    private function columns(TaskChanges $changes): array
    {
        $columns = [];

        foreach ($changes->attributes as $column => $value) {
            $columns[$column] = $value instanceof BackedEnum ? $value->value : $value;
        }

        return $columns;
    }

    /**
     * The completion columns implied by the new status, matching {@see ChangeTaskStatus}.
     *
     * @return array<string, mixed>
     */
    private function completion(TaskChanges $changes): array
    {
        $status = $changes->status;

        if ($status === null) {
            return [];
        }

        if (! $status->is_completed && ! $status->category->isClosed()) {
            return ['completed_at' => null];
        }

        return $status->category->isCompleted()
            ? ['completed_at' => Carbon::now(), 'progress' => 100]
            : ['completed_at' => Carbon::now()];
    }

    /**
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

    private function assertValid(TaskChanges $changes): void
    {
        $columns = $changes->attributes;

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

        if (is_string($columns['start_date'] ?? null) && is_string($columns['due_date'] ?? null)
            && $columns['due_date'] < $columns['start_date']) {
            throw InvalidTaskAttributes::dueBeforeStart();
        }
    }
}
