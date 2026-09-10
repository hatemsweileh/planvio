<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\BulkUpdateTasks;
use App\Actions\Tasks\TaskChanges;
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
 * Apply one edit to many tasks.
 *
 * This is the tool with the largest blast radius at medium risk, so three limits are built
 * into it rather than left to the caller's judgement.
 *
 * **A hard cap.** At most {@see MAX_TASKS} tasks change in one call, whatever the model
 * sends. Ids past the cap are not silently dropped: the result names how many were left out
 * and says the call can be repeated for them, so an agent working through a backlog knows it
 * has not finished. A cap that lied about being one would be worse than no cap.
 *
 * **Every task is authorised individually.** {@see BulkUpdateTasks} writes one `UPDATE` per
 * chunk and asks no permission questions — that is the caller's job, and here the caller is
 * an agent acting for one person whose rights differ from task to task: the capability matrix
 * grants a plain member `task.update` only on the tasks they are the assignee or reporter of.
 * So every task is resolved in the workspace and run through the Gate, and the ones that fail
 * are reported by key rather than quietly included or quietly forgotten.
 *
 * **A board column may not cross projects.** `task_statuses` is per project, so one column
 * cannot legally apply to tasks on two boards. A mixed selection is refused before anything
 * is written rather than half-applied and then thrown.
 */
final class BulkUpdateTasksTool implements AiTool
{
    /**
     * The most tasks one call may change.
     *
     * Sized against the run limits rather than the database: 100 rows is a decision a human
     * reviewing `ai_tool_runs` can still comprehend and undo, and an agent that genuinely
     * needs to move a thousand tasks should be visibly doing it ten calls at a time.
     */
    public const MAX_TASKS = 100;

    use MutatesThroughActions;

    public function __construct(private readonly BulkUpdateTasks $bulkUpdate) {}

    public function name(): string
    {
        return 'bulk_update_tasks';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Apply the same change to several tasks at once — priority, assignee, '
            .'milestone, dates, or a board column. At most '.self::MAX_TASKS.' tasks per '
            .'call; anything beyond that is reported back, not silently skipped. Tasks the '
            .'acting user may not edit are listed and left alone.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_ids'],
            'properties' => [
                'task_ids' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 500,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => 'Task ids to change. At most '.self::MAX_TASKS.' are applied per call.',
                ],
                'status' => [
                    'type' => 'string',
                    'maxLength' => 100,
                    'description' => 'Board column name. All the tasks must be in the same project.',
                ],
                'priority' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high', 'urgent']],
                'assignee_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Null unassigns all of them. Requires permission to assign work.',
                ],
                'milestone_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'start_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'due_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
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
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->apply($in, $ctx));
    }

    private function apply(Arguments $in, AgentContext $ctx): ToolResult
    {
        $requested = $in->ids('task_ids');
        $accepted = array_slice($requested, 0, self::MAX_TASKS);
        $capped = array_slice($requested, self::MAX_TASKS);

        /** @var list<Task> $tasks */
        $tasks = [];
        /** @var list<int> $missing */
        $missing = [];
        /** @var list<string> $refused */
        $refused = [];

        foreach ($accepted as $id) {
            $task = $this->resolveTask($id, $ctx);

            if (! $task instanceof Task) {
                $missing[] = $id;

                continue;
            }

            $ctx->assertInWorkspace($task);

            if ($ctx->cannot(Permission::TaskUpdate, $task)) {
                $refused[] = $task->key;

                continue;
            }

            $tasks[] = $task;
        }

        if ($tasks === []) {
            return ToolResult::failed(
                __('None of those tasks could be changed: :missing not found in this workspace, :refused not editable by :user.', [
                    'missing' => count($missing),
                    'refused' => count($refused),
                    'user' => $ctx->user->name,
                ]),
                'nothing_to_change',
                ['not_found' => $missing, 'not_permitted' => $refused, 'capped' => count($capped)],
            );
        }

        $changes = $this->changes($in, $tasks, $ctx);

        if ($changes instanceof ToolResult) {
            return $changes;
        }

        if ($changes->isEmpty()) {
            return ToolResult::failed(
                __('No fields were given, so none of the :count tasks were changed.', ['count' => count($tasks)]),
                'nothing_to_change',
            );
        }

        $ids = array_map(static fn (Task $task): int => (int) $task->getKey(), $tasks);

        $changed = ($this->bulkUpdate)($ids, $changes, $ctx->user);

        return ToolResult::ok(
            $this->summary($changed, count($ids), $missing, $refused, $capped),
            [
                'changed' => $changed,
                'attempted' => count($ids),
                'already_matching' => count($ids) - $changed,
                'not_found' => $missing,
                'not_permitted' => $refused,
                'capped_ids' => array_slice($capped, 0, 50),
                'capped_count' => count($capped),
                'limit' => self::MAX_TASKS,
                'fields' => array_keys($changes->attributes),
            ],
        );
    }

    /**
     * @param list<Task> $tasks
     */
    private function changes(Arguments $in, array $tasks, AgentContext $ctx): TaskChanges|ToolResult
    {
        $changes = TaskChanges::make();

        if ($in->filled('priority')) {
            $priority = $in->enum('priority', Priority::class);

            if ($priority instanceof Priority) {
                $changes = $changes->priority($priority);
            }
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

        if ($in->has('milestone_id')) {
            $milestone = $this->milestone($in, $tasks, $ctx);

            if ($milestone instanceof ToolResult) {
                return $milestone;
            }

            $changes = $changes->milestone($milestone);
        }

        if ($in->has('assignee_id')) {
            $assignee = $this->assignee($in, $tasks, $ctx);

            if ($assignee instanceof ToolResult) {
                return $assignee;
            }

            $changes = $changes->assignee($assignee);
        }

        if ($in->filled('status')) {
            $status = $this->status($in, $tasks, $ctx);

            if ($status instanceof ToolResult) {
                return $status;
            }

            $changes = $changes->status($status);
        }

        return $changes;
    }

    /**
     * @param list<Task> $tasks
     */
    private function status(Arguments $in, array $tasks, AgentContext $ctx): TaskStatus|ToolResult
    {
        $projectIds = array_values(array_unique(array_map(
            static fn (Task $task): int => (int) $task->project_id,
            $tasks,
        )));

        if (count($projectIds) > 1) {
            return ToolResult::failed(
                __('Those tasks are spread across :count projects and board columns belong to one project each, so nothing was changed. Move them one project at a time.', [
                    'count' => count($projectIds),
                ]),
                'status_across_projects',
                ['project_ids' => $projectIds],
            );
        }

        $project = $tasks[0]->relationLoaded('project') ? $tasks[0]->getRelation('project') : null;

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $projectIds[0]);
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

    /**
     * @param list<Task> $tasks
     */
    private function milestone(Arguments $in, array $tasks, AgentContext $ctx): Milestone|ToolResult|null
    {
        $id = $in->nullableInt('milestone_id');

        if ($id === null) {
            return null;
        }

        $milestone = $this->resolveMilestone($id, $ctx);

        if (! $milestone instanceof Milestone) {
            return $this->notFound(__('milestone'), $id);
        }

        foreach ($tasks as $task) {
            if ((int) $task->project_id !== (int) $milestone->project_id) {
                return ToolResult::failed(
                    __('Task :key is not in the same project as milestone :milestone, so nothing was changed.', [
                        'key' => $task->key,
                        'milestone' => $milestone->name,
                    ]),
                    'milestone_not_in_project',
                );
            }
        }

        return $milestone;
    }

    /**
     * @param list<Task> $tasks
     */
    private function assignee(Arguments $in, array $tasks, AgentContext $ctx): User|ToolResult|null
    {
        foreach ($tasks as $task) {
            if ($ctx->cannot(Permission::TaskAssign, $task)) {
                return $this->denied($ctx, __('assign task :key', ['key' => $task->key]));
            }
        }

        $id = $in->nullableInt('assignee_id');

        if ($id === null) {
            return null;
        }

        return $this->resolveUser($id, $ctx) ?? $this->notFound(__('workspace member'), $id);
    }

    /**
     * @param list<int> $missing
     * @param list<string> $refused
     * @param list<int> $capped
     */
    private function summary(int $changed, int $attempted, array $missing, array $refused, array $capped): string
    {
        $summary = __('Changed :changed of :attempted tasks.', ['changed' => $changed, 'attempted' => $attempted]);

        if ($changed < $attempted) {
            $summary .= ' '.__(':count already had those values.', ['count' => $attempted - $changed]);
        }

        if ($missing !== []) {
            $summary .= ' '.__(':count id(s) were not found in this workspace.', ['count' => count($missing)]);
        }

        if ($refused !== []) {
            $summary .= ' '.__(':count were left alone because the acting user may not edit them: :keys.', [
                'count' => count($refused),
                'keys' => implode(', ', array_slice($refused, 0, 10)),
            ]);
        }

        if ($capped !== []) {
            $summary .= ' '.__(':count more were not attempted because one call changes at most :limit tasks — call again for the rest.', [
                'count' => count($capped),
                'limit' => self::MAX_TASKS,
            ]);
        }

        return $summary;
    }
}
