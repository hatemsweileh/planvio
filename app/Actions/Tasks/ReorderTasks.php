<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\TaskOrderingService;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the order of a whole board column.
 *
 * The ids the client sends are treated as a request, not as the truth: any id that is not a
 * live task in this column is dropped, and any task in the column the client forgot keeps
 * its relative order at the end. A partial or stale list therefore reorders what it can and
 * never makes a card disappear.
 *
 * The result is the same clean multiples of {@see TaskOrderingService::GAP} a renormalisation
 * produces — this *is* a renormalisation, with the order supplied rather than read — so the
 * column is left with room for ordinary drags afterwards.
 *
 * One activity row is written for the column rather than one per card, and no per-task event
 * is raised: dragging one card can renumber two hundred rows, and fanning that out as two
 * hundred domain events would say far more than happened. Timestamps are left alone for the
 * same reason — a position is board layout, not an edit to the task.
 */
final class ReorderTasks
{
    /** Rows per statement while rewriting the column. */
    private const CHUNK = 200;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param list<int> $orderedTaskIds the ids in their new top-to-bottom order
     * @return int the number of rows written
     */
    public function __invoke(TaskStatus $status, array $orderedTaskIds, User $actor): int
    {
        $statusId = (int) $status->getKey();

        return DB::transaction(function () use ($status, $orderedTaskIds, $statusId, $actor): int {
            $current = Task::query()
                ->where('status_id', $statusId)
                ->orderBy('position')
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($current === []) {
                return 0;
            }

            $requested = [];

            foreach ($orderedTaskIds as $id) {
                $id = (int) $id;

                if (in_array($id, $current, true) && ! in_array($id, $requested, true)) {
                    $requested[] = $id;
                }
            }

            $order = [...$requested, ...array_values(array_diff($current, $requested))];

            if ($order === $current) {
                return 0;
            }

            $written = $this->write($order);

            $this->activity->record($status, 'reordered', $actor, [
                'project_id' => (int) $status->project_id,
                'status_id' => $statusId,
                'task_ids' => $order,
            ]);

            return $written;
        });
    }

    /**
     * @param list<int> $order
     */
    private function write(array $order): int
    {
        $written = 0;

        foreach (array_chunk($order, self::CHUNK, true) as $chunk) {
            $bindings = [];
            $cases = '';

            foreach ($chunk as $index => $id) {
                $cases .= ' when ? then ?';
                $bindings[] = $id;
                $bindings[] = number_format(
                    ($index + 1) * TaskOrderingService::GAP,
                    TaskOrderingService::SCALE,
                    '.',
                    '',
                );
            }

            $ids = array_values($chunk);
            $placeholders = implode(', ', array_fill(0, count($ids), '?'));

            $written += DB::update(
                'update tasks set position = case id'.$cases.' end where id in ('.$placeholders.')',
                array_merge($bindings, $ids),
            );
        }

        return $written;
    }
}
