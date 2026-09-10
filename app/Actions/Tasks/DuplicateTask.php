<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskStatus;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Copies a task, optionally with its checklist, tags, watchers and subtasks.
 *
 * Unlike most actions here this one is deliberately *not* idempotent: asking twice for a
 * copy means two copies, because that is what the word means. What it does guarantee is
 * that each copy is a fresh task — its own number, its own position, no completion stamp
 * and no progress inherited from a source that may already be finished.
 */
final class DuplicateTask
{
    /**
     * A ceiling on how much of the subtask tree one copy will walk. Far above any real
     * task, and it turns a pathological tree into a short copy rather than a stalled request.
     */
    private const MAX_NODES = 500;

    public function __construct(
        private readonly CreateTask $createTask,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(
        Task $task,
        User $actor,
        ?string $title = null,
        bool $withSubtasks = true,
        bool $withChecklist = true,
        bool $withTags = true,
        bool $withWatchers = false,
    ): Task {
        $task->loadMissing(['project', 'status', 'assignee', 'milestone']);

        return DB::transaction(function () use (
            $task,
            $actor,
            $title,
            $withSubtasks,
            $withChecklist,
            $withTags,
            $withWatchers,
        ): Task {
            $copy = $this->copy(
                $task,
                $actor,
                $title ?? __('actions.tasks.copy_of', ['title' => $task->title]),
                null,
                $withChecklist,
                $withTags,
                $withWatchers,
            );

            if ($withSubtasks) {
                $this->copySubtaskTree($task, $copy, $actor, $withChecklist, $withTags, $withWatchers);
            }

            $this->activity->record($task, 'duplicated', $actor, [
                'copy_id' => (int) $copy->getKey(),
                'copy_number' => (int) $copy->number,
            ]);

            return $copy;
        });
    }

    /**
     * Walk the source subtask tree breadth first, copying each node under the copy of its
     * parent. Iterative: a queue of (source, new parent) pairs rather than recursion, so a
     * deep tree costs queue entries instead of stack frames.
     */
    private function copySubtaskTree(
        Task $source,
        Task $copy,
        User $actor,
        bool $withChecklist,
        bool $withTags,
        bool $withWatchers,
    ): void {
        $queue = [[$source, $copy]];
        $copied = 0;

        while ($queue !== [] && $copied < self::MAX_NODES) {
            [$sourceParent, $copyParent] = array_shift($queue);

            $children = Task::query()
                ->where('parent_id', $sourceParent->getKey())
                ->with(['status', 'assignee', 'milestone'])
                ->orderBy('position')
                ->orderBy('id')
                ->get();

            foreach ($children as $child) {
                if (++$copied > self::MAX_NODES) {
                    return;
                }

                $child->setRelation('project', $source->getRelation('project'));

                $childCopy = $this->copy(
                    $child,
                    $actor,
                    $child->title,
                    $copyParent,
                    $withChecklist,
                    $withTags,
                    $withWatchers,
                );

                $queue[] = [$child, $childCopy];
            }
        }
    }

    private function copy(
        Task $source,
        User $actor,
        string $title,
        ?Task $parent,
        bool $withChecklist,
        bool $withTags,
        bool $withWatchers,
    ): Task {
        $copy = ($this->createTask)(new CreateTaskData(
            project: $source->getRelation('project'),
            actor: $actor,
            title: $title,
            description: $source->description,
            status: $this->openColumn($source),
            priority: $source->priority,
            assignee: $source->relationLoaded('assignee') ? $source->getRelation('assignee') : null,
            reporter: $actor,
            parent: $parent,
            milestone: $source->relationLoaded('milestone') ? $source->getRelation('milestone') : null,
            startDate: $source->start_date,
            dueDate: $source->due_date,
            estimateMinutes: $source->estimate_minutes,
        ));

        if ($withChecklist) {
            $this->copyChecklist($source, $copy);
        }

        if ($withTags) {
            $tagIds = $source->tags()->pluck('tags.id')->all();

            if ($tagIds !== []) {
                $copy->tags()->syncWithoutDetaching($tagIds);
            }
        }

        if ($withWatchers) {
            $watcherIds = $source->watchers()->pluck('users.id')->all();

            if ($watcherIds !== []) {
                $copy->watchers()->syncWithoutDetaching($watcherIds);
            }
        }

        return $copy;
    }

    /**
     * The column the copy lands in.
     *
     * The source's own column, unless that column closes tasks — copying a finished task is
     * a request for the work again, and a copy that arrives already Done is of no use to
     * anybody. In that one case the project default is used instead, which is also what
     * keeps the copy's `completed_at` empty.
     */
    private function openColumn(Task $source): ?TaskStatus
    {
        $status = $source->relationLoaded('status') ? $source->getRelation('status') : null;

        if (! $status instanceof TaskStatus) {
            return null;
        }

        return $status->is_completed || $status->category->isClosed() ? null : $status;
    }

    /**
     * Items come across unticked: a copy is work still to do, and inheriting somebody
     * else's completion marks would make the new task look half finished before it started.
     */
    private function copyChecklist(Task $source, Task $copy): void
    {
        $items = TaskChecklistItem::query()
            ->forTask($source)
            ->ordered()
            ->get(['title', 'position']);

        if ($items->isEmpty()) {
            return;
        }

        $now = Carbon::now();
        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'task_id' => $copy->getKey(),
                'title' => $item->title,
                'is_done' => false,
                'position' => $item->position,
                'completed_at' => null,
                'completed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        TaskChecklistItem::query()->insert($rows);
    }
}
