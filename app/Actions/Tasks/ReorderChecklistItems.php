<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Reorders the tick boxes on a task.
 *
 * A checklist is short and always rendered whole, so unlike the board it uses plain integer
 * positions and is renumbered outright — there is nothing to gain from fractional gaps on a
 * list of eight items.
 *
 * Ids that do not belong to this task are ignored, and items the caller left out keep their
 * relative order at the end, so a stale list reorders what it can and drops nothing.
 */
final class ReorderChecklistItems
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param list<int> $orderedItemIds
     * @return int the number of items written
     */
    public function __invoke(Task $task, array $orderedItemIds, User $actor): int
    {
        return DB::transaction(function () use ($task, $orderedItemIds, $actor): int {
            $current = TaskChecklistItem::query()
                ->forTask($task)
                ->ordered()
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($current === []) {
                return 0;
            }

            $requested = [];

            foreach ($orderedItemIds as $id) {
                $id = (int) $id;

                if (in_array($id, $current, true) && ! in_array($id, $requested, true)) {
                    $requested[] = $id;
                }
            }

            $order = [...$requested, ...array_values(array_diff($current, $requested))];

            if ($order === $current) {
                return 0;
            }

            foreach ($order as $index => $id) {
                TaskChecklistItem::query()->whereKey($id)->update(['position' => $index + 1]);
            }

            $this->activity->record($task, 'checklist_reordered', $actor, [
                'item_ids' => $order,
            ]);

            return count($order);
        });
    }
}
