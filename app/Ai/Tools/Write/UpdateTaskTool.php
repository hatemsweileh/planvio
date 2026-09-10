<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\TaskChanges;
use App\Actions\Tasks\UpdateTask;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;

/**
 * Edit an existing task.
 *
 * The distinction this tool exists to preserve is *absent* versus *null*: not mentioning a
 * due date and clearing one are different intentions, and a partial edit that cannot tell
 * them apart will eventually wipe a field nobody asked it to touch. `TaskChanges` models that
 * exactly — a column is touched only when it appears in the call — and this tool is careful
 * to build it from `Arguments::has()` rather than from "is the value non-null".
 *
 * Status and assignee are not written here even though they look like ordinary columns:
 * {@see UpdateTask} routes them to `ChangeTaskStatus` and `AssignTask`, which own the
 * completion stamp and the watcher rules. Changing the assignee therefore also needs
 * `task.assign`, checked separately from `task.update` — the two are different cells in the
 * capability matrix and a plain member holds only one of them.
 */
final class UpdateTaskTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly UpdateTask $updateTask) {}

    public function name(): string
    {
        return 'update_task';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Change fields on an existing task. Only the fields you send are touched; '
            .'sending null for assignee_id, milestone_id, start_date or due_date clears them. '
            .'Dates may be written plainly and are read in the workspace timezone; an '
            .'ambiguous phrase is refused rather than guessed.';
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
                'task_id' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 20000],
                'status' => [
                    'type' => 'string',
                    'maxLength' => 100,
                    'description' => 'Board column name on this task\'s own project.',
                ],
                'priority' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high', 'urgent']],
                'assignee_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Null unassigns. Requires permission to assign work.',
                ],
                'milestone_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Null removes the task from its milestone.',
                ],
                'start_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'due_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'estimate_minutes' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 100000],
                'progress' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::TaskUpdate;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->update($in, $ctx));
    }

    private function update(Arguments $in, AgentContext $ctx): ToolResult
    {
        $id = $in->int('task_id');
        $task = $this->resolveTask($id, $ctx);

        if (! $task instanceof Task) {
            return $this->notFound(__('task'), $id);
        }

        $ctx->assertInWorkspace($task);

        if ($ctx->cannot(Permission::TaskUpdate, $task)) {
            return $this->denied($ctx, __('change task :key', ['key' => $task->key]));
        }

        $changes = $this->changes($in, $task, $ctx);

        if ($changes instanceof ToolResult) {
            return $changes;
        }

        if ($changes->isEmpty()) {
            return ToolResult::failed(
                __('No fields were given, so task :key was left as it is.', ['key' => $task->key]),
                'nothing_to_change',
            );
        }

        $task->loadMissing(['status', 'assignee']);

        $before = $this->snapshot($task);

        ($this->updateTask)($task, $changes, $ctx->user);

        $task->refresh()->loadMissing(['project', 'status', 'assignee']);

        $after = $this->snapshot($task);
        $changed = array_keys(array_diff_assoc($after, $before));

        return ToolResult::ok(
            $changed === []
                ? __('Task :key already had those values, so nothing changed.', ['key' => $task->key])
                : __('Updated task :key ":title": changed :fields.', [
                    'key' => $task->key,
                    'title' => self::clip($task->title, 100),
                    'fields' => implode(', ', $changed),
                ]),
            [
                'task_id' => (int) $task->getKey(),
                'key' => $task->key,
                'changed_fields' => $changed,
                'before' => array_intersect_key($before, array_flip($changed)),
                'after' => array_intersect_key($after, array_flip($changed)),
            ],
            $task,
        );
    }

    private function changes(Arguments $in, Task $task, AgentContext $ctx): TaskChanges|ToolResult
    {
        $changes = TaskChanges::make();

        if ($in->filled('title')) {
            $changes = $changes->title((string) $in->text('title'));
        }

        if ($in->has('description')) {
            $changes = $changes->description($in->text('description'));
        }

        if ($in->filled('priority')) {
            $priority = $in->enum('priority', Priority::class);

            if ($priority instanceof Priority) {
                $changes = $changes->priority($priority);
            }
        }

        if ($in->has('estimate_minutes')) {
            $changes = $changes->estimateMinutes($in->nullableInt('estimate_minutes'));
        }

        if ($in->filled('progress')) {
            $changes = $changes->progress($in->int('progress'));
        }

        foreach (['start_date' => 'startDate', 'due_date' => 'dueDate'] as $field => $method) {
            if (! $in->has($field)) {
                continue;
            }

            $date = $this->dateArgument($in, $field, $ctx);

            if ($date instanceof ToolResult) {
                return $date;
            }

            $changes = $changes->{$method}($date);
        }

        if ($in->filled('status')) {
            $status = $this->status($in, $task, $ctx);

            if ($status instanceof ToolResult) {
                return $status;
            }

            $changes = $changes->status($status);
        }

        if ($in->has('milestone_id')) {
            $milestone = $this->milestone($in, $task, $ctx);

            if ($milestone instanceof ToolResult) {
                return $milestone;
            }

            $changes = $changes->milestone($milestone);
        }

        if ($in->has('assignee_id')) {
            $assignee = $this->assignee($in, $task, $ctx);

            if ($assignee instanceof ToolResult) {
                return $assignee;
            }

            $changes = $changes->assignee($assignee);
        }

        return $changes;
    }

    private function status(Arguments $in, Task $task, AgentContext $ctx): TaskStatus|ToolResult
    {
        $project = $task->relationLoaded('project') ? $task->getRelation('project') : null;

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), (int) $task->project_id);
        }

        $name = (string) $in->text('status');
        $status = $this->resolveTaskStatus($project, $name, $ctx);

        if ($status instanceof TaskStatus) {
            return $status;
        }

        return ToolResult::failed(
            __(':project has no board column called ":name". Its columns are: :columns.', [
                'project' => $project->name,
                'name' => self::clip($name, 60),
                'columns' => implode(', ', $this->taskStatusNames($project, $ctx)),
            ]),
            'unknown_status',
        );
    }

    private function milestone(Arguments $in, Task $task, AgentContext $ctx): Milestone|ToolResult|null
    {
        $id = $in->nullableInt('milestone_id');

        if ($id === null) {
            return null;
        }

        $milestone = $this->resolveMilestone($id, $ctx);

        if (! $milestone instanceof Milestone) {
            return $this->notFound(__('milestone'), $id);
        }

        if ((int) $milestone->project_id !== (int) $task->project_id) {
            return ToolResult::failed(
                __('Milestone :milestone belongs to another project, so task :key was not changed.', [
                    'milestone' => $milestone->name,
                    'key' => $task->key,
                ]),
                'milestone_not_in_project',
            );
        }

        return $milestone;
    }

    private function assignee(Arguments $in, Task $task, AgentContext $ctx): User|ToolResult|null
    {
        if ($ctx->cannot(Permission::TaskAssign, $task)) {
            return $this->denied($ctx, __('assign task :key', ['key' => $task->key]));
        }

        $id = $in->nullableInt('assignee_id');

        if ($id === null) {
            return null;
        }

        return $this->resolveUser($id, $ctx) ?? $this->notFound(__('workspace member'), $id);
    }

    /**
     * The fields this tool can touch, reduced to comparable scalars so "what actually
     * changed" is a diff rather than a claim.
     *
     * @return array<string, string|int|null>
     */
    private function snapshot(Task $task): array
    {
        return [
            'title' => $task->title,
            'description' => self::clip($task->description, 200),
            'status' => $task->status?->name,
            'priority' => $task->priority->value,
            'assignee' => $task->assignee?->name,
            'milestone_id' => $task->milestone_id === null ? null : (int) $task->milestone_id,
            'start_date' => $task->start_date?->format('Y-m-d'),
            'due_date' => $task->due_date?->format('Y-m-d'),
            'estimate_minutes' => $task->estimate_minutes === null ? null : (int) $task->estimate_minutes,
            'progress' => (int) $task->progress,
        ];
    }
}
