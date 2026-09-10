<?php

declare(strict_types=1);

namespace App\Actions\Dependencies;

use App\Models\Task;
use App\Models\TaskDependency;

/**
 * Reachability over `task_dependencies`.
 *
 * The table is a directed graph: a row (task_id, depends_on_task_id) is an edge from the
 * task to the thing it waits for. Everything that consumes it — the timeline, the "blocked
 * by" panel, any scheduling order — assumes that graph is acyclic, and nothing in the
 * schema enforces that: `unique(task_id, depends_on_task_id)` stops a duplicate edge, not a
 * loop three edges long.
 *
 * The search is breadth first, iterative, batched one level per query, and bounded twice
 * over — by a visited set and by a hard node budget. Recursion is avoided on purpose: the
 * one thing this code must survive is a graph that already contains a cycle, and a
 * recursive walk over one does not return.
 */
final class DependencyGraph
{
    /**
     * The most nodes a single search will visit. Well past any real dependency web, and low
     * enough that a corrupted graph costs a bounded query rather than a stalled request.
     */
    private const MAX_NODES = 5000;

    /** Ids per level query. */
    private const CHUNK = 200;

    /**
     * The chain of tasks leading from $fromTaskId to $toTaskId along depends-on edges, or
     * null when no such chain exists.
     *
     * The returned path starts at $fromTaskId and ends at $toTaskId, so a caller can name
     * the loop it is refusing rather than merely reporting that there is one.
     *
     * @return list<int>|null
     */
    public function pathBetween(int $fromTaskId, int $toTaskId): ?array
    {
        if ($fromTaskId === $toTaskId) {
            return [$fromTaskId];
        }

        /** @var array<int, int|null> $cameFrom  node => the node it was reached from */
        $cameFrom = [$fromTaskId => null];
        $frontier = [$fromTaskId];
        $visited = 1;

        while ($frontier !== [] && $visited < self::MAX_NODES) {
            $next = [];

            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                $edges = TaskDependency::query()
                    ->whereIn('task_id', $chunk)
                    ->get(['task_id', 'depends_on_task_id']);

                foreach ($edges as $edge) {
                    $neighbour = (int) $edge->depends_on_task_id;

                    if (array_key_exists($neighbour, $cameFrom)) {
                        continue;
                    }

                    $cameFrom[$neighbour] = (int) $edge->task_id;

                    if ($neighbour === $toTaskId) {
                        return $this->reconstruct($cameFrom, $fromTaskId, $toTaskId);
                    }

                    $next[] = $neighbour;

                    if (++$visited >= self::MAX_NODES) {
                        break 3;
                    }
                }
            }

            $frontier = $next;
        }

        return null;
    }

    /**
     * Human-readable task keys for a path, e.g. `WEB-3 -> WEB-7 -> WEB-3`.
     *
     * Ids mean nothing to the person who tripped the rule; the display key is what they see
     * on the board. One query, and any id that cannot be resolved falls back to `#id` rather
     * than dropping out of the chain.
     *
     * @param list<int> $path
     */
    public function describe(array $path): string
    {
        if ($path === []) {
            return '';
        }

        $labels = [];

        $tasks = Task::query()
            ->whereIn('tasks.id', $path)
            ->join('projects', 'projects.id', '=', 'tasks.project_id')
            ->get(['tasks.id as id', 'tasks.number as number', 'projects.key as project_key']);

        foreach ($tasks as $task) {
            $key = $task->getAttribute('project_key');
            $labels[(int) $task->getAttribute('id')] = is_string($key) && $key !== ''
                ? $key.'-'.$task->getAttribute('number')
                : '#'.$task->getAttribute('number');
        }

        return implode(' -> ', array_map(
            static fn (int $id): string => $labels[$id] ?? '#'.$id,
            $path,
        ));
    }

    /**
     * @param array<int, int|null> $cameFrom
     * @return list<int>
     */
    private function reconstruct(array $cameFrom, int $fromTaskId, int $toTaskId): array
    {
        $path = [$toTaskId];
        $cursor = $cameFrom[$toTaskId] ?? null;
        $steps = 0;

        while ($cursor !== null && $steps++ < self::MAX_NODES) {
            array_unshift($path, $cursor);

            if ($cursor === $fromTaskId) {
                break;
            }

            $cursor = $cameFrom[$cursor] ?? null;
        }

        return $path;
    }
}
