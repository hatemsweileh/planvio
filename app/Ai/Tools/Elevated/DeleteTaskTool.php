<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Actions\Tasks\DeleteTask;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\Arguments;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\TimeEntry;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * `delete_task` — soft-delete one task and everything hanging under it.
 *
 * The blast radius is wider than the request looks, which is the whole reason this tool
 * reports consequences. `DeleteTask` walks the `parent_id` tree and stamps every descendant
 * with the same `deleted_at`, so deleting an epic takes its subtasks with it. Somebody
 * approving "delete WEB-42" is entitled to be told that WEB-42 has nine children, forty hours
 * logged against it and two other tasks depending on it.
 *
 * On the unwaivable approval list: no mode and no `AiPolicy` executes this without a person
 * (AI_SECURITY.md, "Approval gating"), enforced by the runner's gate and again by
 * {@see ElevatedTool::approvalGate()} here.
 */
final class DeleteTaskTool extends ElevatedTool
{
    /** Ids per statement when walking or counting across a subtask tree. */
    private const CHUNK = 200;

    /** Mirrors the guard in the Action: a `parent_id` cycle must not loop forever. */
    private const MAX_DEPTH = 50;

    public function __construct(private readonly DeleteTask $deleteTask) {}

    public function name(): string
    {
        return 'delete_task';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Delete one task and every subtask beneath it. They move to the trash and can '
            .'be restored. Always requires a human approval, in every mode and under every '
            .'policy. Deleting a parent deletes its whole subtree, so state the subtask count '
            .'when you propose it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_id'],
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The task to delete. One per call; its subtasks go with it.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Destructive;
    }

    public function permission(): ?Permission
    {
        return Permission::TaskDelete;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle(
            $args,
            $ctx,
            fn (Arguments $in, AgentContext $ctx): ToolResult => $this->delete($args, $in, $ctx),
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array
    {
        $id = $args['task_id'] ?? null;
        $task = $this->resolveTask(is_numeric($id) ? (int) $id : 0, $ctx);

        return $task instanceof Task ? $this->countsFor($task, $ctx) : [];
    }

    /**
     * @param array<string, mixed> $args the raw call, for the approval key
     */
    private function delete(array $args, Arguments $in, AgentContext $ctx): ToolResult
    {
        $id = $in->int('task_id');
        $task = $this->resolveTask($id, $ctx);

        if (! $task instanceof Task) {
            return $this->notFound(__('task'), $id);
        }

        $ctx->assertInWorkspace($task);

        if ($ctx->cannot(Permission::TaskDelete, $task)) {
            return $this->denied($ctx, __('delete task :key', ['key' => $task->key]));
        }

        // Nothing past this line runs without a recorded human approval, whatever the mode
        // and whatever any policy says.
        $blocked = $this->approvalGate($args, $ctx, $task);

        if ($blocked instanceof ToolResult) {
            return $blocked;
        }

        // Counted first: the subtree is what the delete consumes, and after the Action runs
        // the same queries would report nothing.
        $facts = $this->countsFor($task, $ctx);

        ($this->deleteTask)($task, $ctx->user);

        return ToolResult::ok(
            __('Deleted :key ":title" and :subtasks subtasks. They are in the trash and can be restored.', [
                'key' => $task->key,
                'title' => self::clip($task->title, 80),
                'subtasks' => $facts['subtasks'],
            ]),
            $facts,
            $task,
        );
    }

    /**
     * The subtree and everything attached to it, as aggregates.
     *
     * The descendant ids have to be materialised — counting a tree without walking it means a
     * recursive CTE, and raw SQL is not something a tool may reach for. The walk is one query
     * per level, capped at {@see self::MAX_DEPTH}, and it plucks ids rather than hydrating
     * models. Everything after it is a `count()` or a `sum()` over `whereIn`, chunked so the
     * statement stays a sensible size on a deep tree.
     *
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    private function countsFor(Task $task, AgentContext $ctx): array
    {
        return $ctx->bindWorkspace(function () use ($task): array {
            $rootId = (int) $task->getKey();
            $descendantIds = $this->descendantIds($rootId);
            $ids = array_merge([$rootId], $descendantIds);
            $morph = $task->getMorphClass();

            return [
                'task' => (string) $task->key,
                'title' => self::clip($task->title, 80),
                'project' => self::clip($task->project?->name, 80),
                'subtasks' => count($descendantIds),
                'checklist_items' => $this->countAcross(
                    $ids,
                    static fn (array $chunk): Builder => TaskChecklistItem::query()->whereIn('task_id', $chunk),
                ),
                'comments' => $this->countAcross(
                    $ids,
                    static fn (array $chunk): Builder => Comment::query()
                        ->where('commentable_type', $morph)
                        ->whereIn('commentable_id', $chunk),
                ),
                'attachments' => $this->countAcross(
                    $ids,
                    static fn (array $chunk): Builder => Attachment::query()
                        ->where('attachable_type', $morph)
                        ->whereIn('attachable_id', $chunk),
                ),
                'time_entries' => $this->countAcross(
                    $ids,
                    static fn (array $chunk): Builder => TimeEntry::query()->whereIn('task_id', $chunk),
                ),
                'logged_minutes' => $this->sumAcross(
                    $ids,
                    static fn (array $chunk): Builder => TimeEntry::query()->whereIn('task_id', $chunk),
                    'minutes',
                ),
                'blocked_tasks' => $this->countAcross(
                    $ids,
                    static fn (array $chunk): Builder => TaskDependency::query()->whereIn('depends_on_task_id', $chunk),
                ),
                'activities' => $this->countAcross(
                    $ids,
                    static fn (array $chunk): Builder => Activity::query()
                        ->where('subject_type', $morph)
                        ->whereIn('subject_id', $chunk),
                ),
            ];
        });
    }

    /**
     * Every live task below $rootId, breadth first — the same walk the Action performs, so the
     * number on the approval card is the number that will actually be deleted.
     *
     * @return list<int>
     */
    private function descendantIds(int $rootId): array
    {
        $found = [];
        $frontier = [$rootId];
        $depth = 0;

        while ($frontier !== [] && $depth++ < self::MAX_DEPTH) {
            $next = [];

            foreach (array_chunk($frontier, self::CHUNK) as $chunk) {
                $children = Task::query()->whereIn('parent_id', $chunk)->pluck('id');

                foreach ($children as $id) {
                    $id = (int) $id;

                    if ($id !== $rootId && ! in_array($id, $found, true)) {
                        $found[] = $id;
                        $next[] = $id;
                    }
                }
            }

            $frontier = $next;
        }

        return $found;
    }

    /**
     * @param list<int> $ids
     * @param Closure(list<int>): Builder $factory
     */
    private function countAcross(array $ids, Closure $factory): int
    {
        $total = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $total += $factory($chunk)->count();
        }

        return $total;
    }

    /**
     * @param list<int> $ids
     * @param Closure(list<int>): Builder $factory
     */
    private function sumAcross(array $ids, Closure $factory, string $column): int
    {
        $total = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $total += (int) $factory($chunk)->sum($column);
        }

        return $total;
    }
}
