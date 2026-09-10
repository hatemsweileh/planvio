<?php

declare(strict_types=1);

namespace App\Ai\Context;

use App\Ai\Agent\AgentContext;
use App\Enums\AuthorType;
use App\Enums\Permission;
use App\Models\Comment;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskDependency;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The task the run is focused on, plus the records that give it meaning: its parent, its
 * subtasks, its checklist, what it blocks and is blocked by, who is watching it, and the most
 * recent comments.
 *
 * Everything here is text other people wrote — titles, descriptions, comment bodies — which
 * makes this the provider most likely to carry an injection attempt into the prompt. That is
 * fine and expected: every fragment is `trusted: false`, PromptBuilder wraps it, and the
 * system prompt's standing rule covers it. What matters is that the wrapping is not the only
 * defence — a model talked into calling a tool still cannot exceed the acting user's
 * permissions (AI_SECURITY.md, "Prompt injection").
 *
 * Related records are re-checked individually rather than assumed readable from the focused
 * task: a dependency can point at a task in another project the acting user has no access to,
 * and the dependency row is not permission to read its target.
 */
final class TaskContextProvider implements ContextSource
{
    use ContributesContext;

    private const MAX_CHECKLIST_ITEMS = 25;

    private const MAX_WATCHERS = 15;

    private const MAX_DEPENDENCIES = 15;

    public function key(): string
    {
        return 'task';
    }

    public function supports(AgentContext $context): bool
    {
        $task = $context->task;

        return $task !== null
            && $context->isInWorkspace($task)
            && $context->can(Permission::TaskView, $task);
    }

    public function provide(AgentContext $context): array
    {
        $task = $context->task;

        if ($task === null) {
            return [];
        }

        $id = (int) $task->getKey();
        $timezone = $context->resolvedTimezone();

        $fragments = [
            ContextFragment::make('task:'.$id, $this->summary($context, $task, $timezone)),
        ];

        $this->push($fragments, 'task:'.$id.':parent', $this->parent($context, $task, $timezone));
        $this->push($fragments, 'task:'.$id.':subtasks', $this->subtasks($context, $task, $timezone));
        $this->push($fragments, 'task:'.$id.':checklist', $this->checklist($task));
        $this->push($fragments, 'task:'.$id.':dependencies', $this->dependencies($context, $task));
        $this->push($fragments, 'task:'.$id.':watchers', $this->watchers($task));
        $this->push($fragments, 'task:'.$id.':comments', $this->comments($context, $task, $timezone));

        return $fragments;
    }

    /* ------------------------------------------------------------------ *
     * Sections
     * ------------------------------------------------------------------ */

    private function summary(AgentContext $context, Task $task, string $timezone): string
    {
        $task->loadMissing(['project:id,key,name', 'status:id,name,category,is_completed', 'milestone:id,name']);

        return Facts::for('Focused task')
            ->add('key', $task->key)
            ->add('title', $task->title)
            ->add('project', $task->project === null
                ? null
                : $task->project->key.' — '.$task->project->name)
            ->add('status', $task->status?->name)
            ->add('status category', $task->status?->category)
            ->add('completed', $task->is_completed)
            ->add('priority', $task->priority)
            ->add('assignee', $this->userLabel($task->assignee_id))
            ->add('reporter', $this->userLabel($task->reporter_id))
            ->add('milestone', $task->milestone?->name)
            ->add('start date', Facts::date($task->start_date, $timezone))
            ->add('due date', Facts::date($task->due_date, $timezone))
            ->add('overdue', $task->is_overdue)
            ->add('completed at', Facts::dateTime($task->completed_at, $timezone))
            ->add('estimate', Facts::minutesAsHours($task->estimate_minutes))
            ->count('progress %', (int) $task->progress)
            ->add('created by AI', $task->ai_generated)
            ->add('description', Facts::excerpt($task->description))
            ->toString();
    }

    private function parent(AgentContext $context, Task $task, string $timezone): ?string
    {
        if ($task->parent_id === null) {
            return null;
        }

        $parent = Task::query()
            ->where('workspace_id', $context->workspaceId())
            ->whereKey($task->parent_id)
            ->with('project:id,key', 'status:id,name')
            ->first();

        if ($parent === null || $context->cannot(Permission::TaskView, $parent)) {
            return null;
        }

        return Facts::for('Parent task')
            ->add('key', $parent->key)
            ->add('title', $parent->title)
            ->add('status', $parent->status?->name)
            ->add('due date', Facts::date($parent->due_date, $timezone))
            ->toString();
    }

    private function subtasks(AgentContext $context, Task $task, string $timezone): ?string
    {
        $subtasks = Task::query()
            ->where('workspace_id', $context->workspaceId())
            ->where('parent_id', $task->getKey())
            ->with('project:id,key', 'status:id,name,is_completed')
            ->orderBy('position')
            ->orderBy('id')
            ->limit($this->limit('max_tasks', 40))
            ->get();

        $lines = [];

        foreach ($subtasks as $subtask) {
            if ($context->cannot(Permission::TaskView, $subtask)) {
                continue;
            }

            $lines[] = $this->taskLine($subtask, $timezone);
        }

        return $lines === []
            ? null
            : Facts::for('Subtasks')->bullets('subtasks', $lines)->toString();
    }

    private function checklist(Task $task): ?string
    {
        $items = TaskChecklistItem::query()
            ->where('task_id', $task->getKey())
            ->orderBy('position')
            ->orderBy('id')
            ->limit(self::MAX_CHECKLIST_ITEMS)
            ->get();

        if ($items->isEmpty()) {
            return null;
        }

        $lines = [];
        $done = 0;

        foreach ($items as $item) {
            $done += $item->is_done ? 1 : 0;
            $lines[] = ($item->is_done ? '[x] ' : '[ ] ').$item->title;
        }

        return Facts::for('Checklist')
            ->count('items', $items->count())
            ->count('done', $done)
            ->bullets('items', $lines)
            ->toString();
    }

    /**
     * Both directions, because "what is this waiting on" and "what is waiting on this" are
     * different questions and the model needs both to reason about a schedule.
     */
    private function dependencies(AgentContext $context, Task $task): ?string
    {
        $rows = TaskDependency::query()
            ->where('workspace_id', $context->workspaceId())
            ->where(function (Builder $query) use ($task): void {
                $query
                    ->where('task_id', $task->getKey())
                    ->orWhere('depends_on_task_id', $task->getKey());
            })
            ->limit(self::MAX_DEPENDENCIES * 2)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $relatedIds = [];

        foreach ($rows as $row) {
            $relatedIds[] = (int) $row->task_id;
            $relatedIds[] = (int) $row->depends_on_task_id;
        }

        $related = Task::query()
            ->where('workspace_id', $context->workspaceId())
            ->whereIn('id', array_values(array_unique($relatedIds)))
            ->with('project:id,key', 'status:id,name')
            ->get()
            ->keyBy(static fn (Task $related): int => (int) $related->getKey());

        $blockedBy = [];
        $blocking = [];

        foreach ($rows as $row) {
            $isOutgoing = (int) $row->task_id === (int) $task->getKey();
            $otherId = $isOutgoing ? (int) $row->depends_on_task_id : (int) $row->task_id;
            $other = $related->get($otherId);

            // A dependency row is not permission to read what it points at.
            if (! $other instanceof Task || $context->cannot(Permission::TaskView, $other)) {
                continue;
            }

            $line = sprintf(
                '%s — %s (%s, %s)',
                $other->key,
                $other->title,
                $row->type?->value ?? 'finish_to_start',
                $other->status?->name ?? 'unknown status',
            );

            if ($isOutgoing) {
                $blockedBy[] = $line;
            } else {
                $blocking[] = $line;
            }
        }

        if ($blockedBy === [] && $blocking === []) {
            return null;
        }

        return Facts::for('Dependencies')
            ->bullets('this task depends on', array_slice($blockedBy, 0, self::MAX_DEPENDENCIES))
            ->bullets('tasks depending on this one', array_slice($blocking, 0, self::MAX_DEPENDENCIES))
            ->toString();
    }

    private function watchers(Task $task): ?string
    {
        $watchers = $task->watchers()
            ->limit(self::MAX_WATCHERS)
            ->get(['users.id', 'users.name']);

        if ($watchers->isEmpty()) {
            return null;
        }

        return Facts::for('Watchers')
            ->bullets('watchers', $watchers->map(
                static fn (User $user): string => '#'.(int) $user->getKey().' '.$user->name,
            )->all())
            ->toString();
    }

    private function comments(AgentContext $context, Task $task, string $timezone): ?string
    {
        // Readable because the task is: supports() already established `task.view` for it,
        // and reading a comment on a task is not separately gated.
        $limit = $this->limit('max_comments', 15);

        $comments = Comment::query()
            ->where('workspace_id', $context->workspaceId())
            ->where('commentable_type', $task->getMorphClass())
            ->where('commentable_id', $task->getKey())
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            // Newest first out of the database for the cap, oldest first into the prompt so
            // the thread reads in the order it happened.
            ->reverse()
            ->values();

        if ($comments->isEmpty()) {
            return null;
        }

        $lines = [];

        foreach ($comments as $comment) {
            $author = $comment->author_type === AuthorType::Ai
                ? 'Planvio AI'
                : ($comment->user?->name ?? 'a removed user');

            $lines[] = sprintf(
                '%s · %s: %s',
                Facts::dateTime($comment->created_at, $timezone) ?? 'unknown time',
                $author,
                Facts::excerpt($comment->body) ?? '(empty)',
            );
        }

        return Facts::for('Recent comments')->bullets('comments', $lines)->toString();
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    private function taskLine(Task $task, string $timezone): string
    {
        $parts = array_values(array_filter([
            $task->status?->name,
            $task->priority?->value === null ? null : 'priority '.$task->priority->value,
            $task->due_date === null ? null : 'due '.Facts::date($task->due_date, $timezone),
            $task->assignee_id === null ? 'unassigned' : 'assignee #'.(int) $task->assignee_id,
        ]));

        return sprintf('%s — %s (%s)', $task->key, $task->title, implode(', ', $parts));
    }

    private function userLabel(mixed $userId): ?string
    {
        if (! is_int($userId) && ! (is_string($userId) && ctype_digit($userId))) {
            return null;
        }

        $user = User::query()->find((int) $userId, ['id', 'name']);

        return $user === null ? null : '#'.(int) $user->getKey().' '.$user->name;
    }

    /**
     * @param list<ContextFragment> $fragments
     */
    private function push(array &$fragments, string $source, ?string $content): void
    {
        if ($content !== null && trim($content) !== '') {
            $fragments[] = ContextFragment::make($source, $content);
        }
    }

    private function limit(string $key, int $default): int
    {
        $configured = config('ai.context.'.$key);

        return is_int($configured) && $configured > 0 ? $configured : $default;
    }
}
