<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\AssigneeNotInWorkspace;
use App\Actions\Tasks\AssignTask;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Task;
use App\Models\User;

/**
 * Hand a task to somebody, or take it back off them.
 *
 * The failure mode this tool is written against is substitution. An agent told to assign
 * work to a person who cannot hold it — someone who left the workspace, someone who was never
 * in this project, a name it half-recognised — has an obvious way to look useful: pick
 * somebody plausible instead. That produces a task assigned to a person who never agreed to
 * it, and a run report that says the request succeeded.
 *
 * So `AssigneeNotInWorkspace` is surfaced, not handled. The action refuses, the refusal is
 * reported with the id that was asked for and what to do about it, and nothing is written.
 * There is no branch below that picks a different person, retries against the project's
 * members, or falls back to the acting user.
 */
final class AssignTaskTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly AssignTask $assignTask) {}

    public function name(): string
    {
        return 'assign_task';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Assign a task to a workspace member, or pass null for assignee_id to '
            .'unassign it. Fails if the person is not a member of the workspace — it will '
            .'never substitute somebody else.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_id', 'assignee_id'],
            'properties' => [
                'task_id' => ['type' => 'integer', 'minimum' => 1],
                'assignee_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'User id of the new assignee, or null to leave the task unassigned.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::TaskAssign;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->assign($in, $ctx));
    }

    private function assign(Arguments $in, AgentContext $ctx): ToolResult
    {
        $taskId = $in->int('task_id');
        $task = $this->resolveTask($taskId, $ctx);

        if (! $task instanceof Task) {
            return $this->notFound(__('task'), $taskId);
        }

        $ctx->assertInWorkspace($task);

        if ($ctx->cannot(Permission::TaskAssign, $task)) {
            return $this->denied($ctx, __('assign task :key', ['key' => $task->key]));
        }

        $assignee = null;

        if ($in->filled('assignee_id')) {
            $assigneeId = $in->int('assignee_id');
            $assignee = $this->resolveUser($assigneeId, $ctx);

            if (! $assignee instanceof User) {
                return ToolResult::failed(
                    __('User :id is not a member of this workspace, so task :key was left with its current assignee. Ask who should hold it, or invite them first — do not pick somebody else.', [
                        'id' => $assigneeId,
                        'key' => $task->key,
                    ]),
                    'assignee_not_in_workspace',
                    ['task_id' => (int) $task->getKey(), 'assignee_id' => $assigneeId],
                );
            }
        }

        $previous = $task->assignee_id === null ? null : (int) $task->assignee_id;

        try {
            ($this->assignTask)($task, $assignee, $ctx->user);
        } catch (AssigneeNotInWorkspace $e) {
            // Belt to the resolver's braces: the action is the authority on this invariant,
            // and its refusal is reported as it stands rather than worked around.
            return ToolResult::failed($e->getMessage(), 'assignee_not_in_workspace');
        }

        if ($previous === ($assignee === null ? null : (int) $assignee->getKey())) {
            return ToolResult::ok(
                $assignee instanceof User
                    ? __('Task :key was already assigned to :name; nothing changed.', [
                        'key' => $task->key,
                        'name' => $assignee->name,
                    ])
                    : __('Task :key was already unassigned; nothing changed.', ['key' => $task->key]),
                ['task_id' => (int) $task->getKey(), 'assignee_id' => $previous],
                $task,
            );
        }

        return ToolResult::ok(
            $assignee instanceof User
                ? __('Assigned task :key ":title" to :name.', [
                    'key' => $task->key,
                    'title' => self::clip($task->title, 100),
                    'name' => $assignee->name,
                ])
                : __('Unassigned task :key ":title".', [
                    'key' => $task->key,
                    'title' => self::clip($task->title, 100),
                ]),
            [
                'task_id' => (int) $task->getKey(),
                'key' => $task->key,
                'previous_assignee_id' => $previous,
                'assignee_id' => $assignee === null ? null : (int) $assignee->getKey(),
                'assignee' => $assignee?->name,
            ],
            $task,
        );
    }
}
