<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Models\Comment;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One task, with the things a person opening it would see: its subtasks, its checklist,
 * what it blocks and is blocked by, and the last few comments.
 *
 * Comments are gated separately from the task itself. `CommentPolicy::viewAny()` is asked
 * for this specific task, and when it says no the section is left out with a note saying so
 * — the model must not conclude from an empty list that nobody has commented.
 *
 * Every text field arrives as sanitised HTML and leaves as clipped plain text. The markup
 * carries nothing the model can use and a long description would spend the whole result
 * budget on tags. What it does carry is other people's writing, which is why the caller
 * wraps this result in `<untrusted-data>` before it reaches the prompt — that wrapping
 * belongs to PromptBuilder and is deliberately not done here (ARCHITECTURE.md section 7.6).
 */
final class GetTaskTool extends ReadTool
{
    private const MAX_SUBTASKS = 20;

    private const MAX_CHECKLIST = 30;

    private const MAX_DEPENDENCIES = 15;

    private const MAX_COMMENTS = 10;

    public function name(): string
    {
        return 'get_task';
    }

    public function description(): string
    {
        return 'Read one task in full: status, assignee, dates, estimate, progress, its '
            .'subtasks, checklist items, dependencies in both directions and its most recent '
            .'comments. Accepts a task id or a display key such as WEB-42.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task'],
            'properties' => [
                'task' => [
                    'type' => ['integer', 'string'],
                    'description' => 'The task id, or its display key such as WEB-42.',
                ],
                'comment_limit' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 10,
                    'description' => 'How many recent comments to include. 0 skips them.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::TaskView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $reference = $args['task'];
        $reference = is_int($reference) ? $reference : (string) $reference;

        $task = $this->resolveTask($ctx, $reference);

        if ($task === null) {
            return $this->notFound('task', $reference);
        }

        $ctx->assertInWorkspace($task);

        if ($ctx->cannot(Permission::TaskView, $task)) {
            return $this->denied('task');
        }

        $task->loadMissing([
            'assignee:id,name',
            'reporter:id,name',
            'creator:id,name',
            'milestone:id,name,status,due_date',
            'parent:id,number,title,project_id',
            'tags:id,name',
        ]);

        $timezone = $ctx->resolvedTimezone();
        $today = $ctx->today()->toDateString();
        $due = self::day($task->due_date);

        $data = [
            'id' => (int) $task->getKey(),
            'key' => (string) $task->key,
            'title' => (string) $task->title,
            'description' => self::excerpt($task->description, 1200),
            'project' => [
                'id' => (int) $task->project_id,
                'key' => $task->project?->key,
                'name' => $task->project?->name,
            ],
            'status' => $task->status?->name,
            'status_category' => $task->status?->category?->value,
            'is_completed' => $task->completed_at !== null,
            'priority' => $task->priority?->value,
            'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
            'assignee' => $task->assignee?->name,
            'reporter' => $task->reporter?->name,
            'created_by' => $task->creator?->name,
            'ai_generated' => (bool) $task->ai_generated,
            'start_date' => self::day($task->start_date),
            'due_date' => $due,
            'is_overdue' => $due !== null && $task->completed_at === null && $due < $today,
            'completed_at' => self::moment($task->completed_at, $timezone),
            'estimate_minutes' => $task->estimate_minutes === null ? null : (int) $task->estimate_minutes,
            'progress' => (int) $task->progress,
            'milestone' => $task->milestone === null ? null : [
                'id' => (int) $task->milestone->getKey(),
                'name' => (string) $task->milestone->name,
                'status' => $task->milestone->status?->value,
                'due_date' => self::day($task->milestone->due_date),
            ],
            'parent' => $task->parent === null ? null : [
                'id' => (int) $task->parent->getKey(),
                'title' => (string) $task->parent->title,
            ],
            'tags' => $task->tags->pluck('name')->all(),
            'subtasks' => $this->subtasks($ctx, $task),
            'checklist' => $this->checklist($task),
            'blocked_by' => $this->dependencies($ctx, $task, blocking: true),
            'blocks' => $this->dependencies($ctx, $task, blocking: false),
        ];

        $commentLimit = is_int($args['comment_limit'] ?? null)
            ? min($args['comment_limit'], self::MAX_COMMENTS)
            : self::MAX_COMMENTS;

        if ($commentLimit < 1) {
            $data['comments'] = ['returned' => 0, 'total' => 0, 'omitted' => 0, 'list' => []];
        } elseif ($ctx->allows('viewAny', [Comment::class, $task])) {
            $data['comments'] = $this->comments($ctx, $task, $commentLimit, $timezone);
        } else {
            $data['comments_note'] = __('ai.tools.notes.comments_withheld');
        }

        $data = $this->fit($data, 'subtasks');

        return ToolResult::ok(
            __('ai.tools.summary.task', [
                'key' => (string) $task->key,
                'title' => self::clip((string) $task->title, 80),
                'status' => $task->status?->name ?? '-',
                'assignee' => $task->assignee?->name ?? __('ai.tools.labels.unassigned'),
                'due' => $due === null ? __('ai.tools.labels.no_due_date') : 'due '.$due,
            ]),
            $data,
            $task,
        );
    }

    /**
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>}
     */
    private function subtasks(AgentContext $ctx, Task $task): array
    {
        $total = Task::query()
            ->forWorkspace($ctx->workspace)
            ->where('tasks.parent_id', $task->getKey())
            ->count();

        $subtasks = Task::query()
            ->forWorkspace($ctx->workspace)
            ->where('tasks.parent_id', $task->getKey())
            ->with(['status:id,name,category', 'assignee:id,name', 'project:id,key'])
            ->orderBy('tasks.position')
            ->orderBy('tasks.id')
            ->limit(self::MAX_SUBTASKS)
            ->get();

        $rows = [];

        foreach ($subtasks as $subtask) {
            $rows[] = [
                'id' => (int) $subtask->getKey(),
                'key' => (string) $subtask->key,
                'title' => (string) $subtask->title,
                'status' => $subtask->status?->name,
                'is_completed' => $subtask->completed_at !== null,
                'assignee' => $subtask->assignee?->name,
                'due_date' => self::day($subtask->due_date),
            ];
        }

        return $this->page($rows, $total, 'list');
    }

    /**
     * @return array{returned: int, total: int, omitted: int, done: int, list: list<array<string, mixed>>}
     */
    private function checklist(Task $task): array
    {
        $total = TaskChecklistItem::query()->forTask($task)->count();
        $done = TaskChecklistItem::query()->forTask($task)->done()->count();

        $items = TaskChecklistItem::query()
            ->forTask($task)
            ->ordered()
            ->limit(self::MAX_CHECKLIST)
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $rows[] = [
                'id' => (int) $item->getKey(),
                'title' => self::clip((string) $item->title, 160),
                'is_done' => (bool) $item->is_done,
            ];
        }

        return $this->page($rows, $total, 'list') + ['done' => $done];
    }

    /**
     * `blocked_by` reads the rows this task declares; `blocks` reads the rows that name it.
     * Both are constrained to the bound workspace and to tasks in projects the acting user
     * may see, so a dependency reaching outside either boundary is simply absent.
     *
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>}
     */
    private function dependencies(AgentContext $ctx, Task $task, bool $blocking): array
    {
        $relation = $blocking ? 'dependsOnTask' : 'task';

        $total = $this->dependencyQuery($ctx, $task, $blocking)->count();

        $dependencies = $this->dependencyQuery($ctx, $task, $blocking)
            ->with([$relation.':id,number,title,project_id,completed_at'])
            ->limit(self::MAX_DEPENDENCIES)
            ->get();

        $rows = [];

        foreach ($dependencies as $dependency) {
            $other = $dependency->getRelation($relation);

            if (! $other instanceof Task) {
                continue;
            }

            $rows[] = [
                'task_id' => (int) $other->getKey(),
                'title' => self::clip((string) $other->title, 120),
                'type' => $dependency->type?->value,
                'is_completed' => $other->completed_at !== null,
            ];
        }

        return $this->page($rows, $total, 'list');
    }

    /**
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>}
     */
    private function comments(AgentContext $ctx, Task $task, int $limit, string $timezone): array
    {
        $total = Comment::query()
            ->forWorkspace($ctx->workspace)
            ->forCommentable($task)
            ->count();

        $comments = Comment::query()
            ->forWorkspace($ctx->workspace)
            ->forCommentable($task)
            ->with('user:id,name')
            ->orderByDesc('comments.created_at')
            ->orderByDesc('comments.id')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($comments as $comment) {
            $rows[] = [
                'id' => (int) $comment->getKey(),
                'author' => $comment->user?->name,
                'author_type' => $comment->author_type?->value,
                'created_at' => self::moment($comment->created_at, $timezone),
                'body' => self::excerpt($comment->body, 300),
            ];
        }

        return $this->page($rows, $total, 'list');
    }

    /**
     * @return Builder<TaskDependency>
     */
    private function dependencyQuery(AgentContext $ctx, Task $task, bool $blocking): Builder
    {
        $column = $blocking ? 'task_dependencies.task_id' : 'task_dependencies.depends_on_task_id';
        $otherColumn = $blocking ? 'task_dependencies.depends_on_task_id' : 'task_dependencies.task_id';

        return TaskDependency::query()
            ->forWorkspace($ctx->workspace)
            ->where($column, $task->getKey())
            ->whereIn($otherColumn, $this->visibleTaskIds($ctx));
    }

    /**
     * Task ids the acting user may reach, as a subquery. Reused by both dependency
     * directions so neither can leak a task from a project the caller cannot open.
     */
    private function visibleTaskIds(AgentContext $ctx): QueryBuilder
    {
        return Task::query()
            ->forWorkspace($ctx->workspace)
            ->whereIn('tasks.project_id', $this->visibleProjectIds($ctx))
            ->select('tasks.id')
            ->toBase();
    }
}
