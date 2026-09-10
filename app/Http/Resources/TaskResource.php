<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tag;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A task.
 *
 * `key` is the identifier a person quotes — "WEB-42" — and it needs the project's prefix, so
 * it is only correct when the project relation is loaded. {@see Task::key()} falls back to
 * `#42` rather than issuing a query for the sake of a label, which is the right behaviour on
 * a board and the wrong behaviour in an API contract: a client that saw `#42` on one endpoint
 * and `WEB-42` on another would reasonably conclude they were different fields. So every
 * controller that renders a task eager-loads `project`, and this resource states the
 * requirement rather than hiding it.
 *
 * `is_completed` and `is_overdue` are derived rather than stored. They are included because
 * every integration recomputes them otherwise, and every integration gets the edge cases
 * wrong: "completed" is a property of the task's *status*, not of a date column, and
 * "overdue" has to exclude completed work.
 *
 * @property Task $resource
 */
final class TaskResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $task = $this->resource;

        return [
            'id' => (int) $task->getKey(),
            'workspace_id' => self::id($task->workspace_id),
            'project_id' => self::id($task->project_id),
            'number' => (int) $task->number,
            'key' => (string) $task->key,
            'title' => (string) $task->title,
            'description' => $task->description === null ? null : (string) $task->description,
            'status_id' => self::id($task->status_id),
            'priority' => self::enum($task->priority),
            'assignee_id' => self::id($task->assignee_id),
            'reporter_id' => self::id($task->reporter_id),
            'created_by' => self::id($task->created_by),
            'parent_id' => self::id($task->parent_id),
            'milestone_id' => self::id($task->milestone_id),
            'start_date' => self::date($task->start_date),
            'due_date' => self::date($task->due_date),
            'completed_at' => self::iso($task->completed_at),
            'estimate_minutes' => $task->estimate_minutes === null ? null : (int) $task->estimate_minutes,
            'progress' => (int) $task->progress,
            'is_completed' => (bool) $task->is_completed,
            'is_overdue' => (bool) $task->is_overdue,
            'ai_generated' => (bool) $task->ai_generated,
            'created_at' => self::iso($task->created_at),
            'updated_at' => self::iso($task->updated_at),
            'url' => self::appUrl('/tasks/'.$task->getKey()),

            'status' => $this->whenLoaded('status', fn (): ?array => $task->status === null
                ? null
                : (new TaskStatusResource($task->status))->resolve($request)),
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $task->assignee === null
                ? null
                : (new UserResource($task->assignee))->resolve($request)),
            // A reference, not the record. A page of fifty tasks from one project would
            // otherwise carry fifty identical copies of that project; `project_id` is there
            // for a caller that wants the rest of it.
            'project' => $this->whenLoaded('project', fn (): ?array => $task->project === null
                ? null
                : [
                    'id' => (int) $task->project->getKey(),
                    'key' => (string) $task->project->key,
                    'name' => (string) $task->project->name,
                    'slug' => (string) $task->project->slug,
                ]),
            'tags' => $this->whenLoaded('tags', fn (): array => Collection::make($task->tags)
                ->map(static fn (Tag $tag): array => (new TagResource($tag))->resolve($request))
                ->values()
                ->all()),
        ];
    }
}
