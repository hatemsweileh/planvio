<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\ChangeTaskStatus;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;

/**
 * Move a task into another board column.
 *
 * Board columns are per project, so "Done" is not one thing: every project has its own, and
 * moving a task into another project's column would put it on a board it can never be seen
 * on. The column is therefore resolved inside the task's own project — by name, because that
 * is what the model reads in context, or by id when it has one — and a name that matches
 * nothing comes back with the list of columns that do exist rather than a bare failure.
 *
 * Completion is not an argument. `completed_at` is written by {@see ChangeTaskStatus} from
 * the category of the column the task lands in, which is what keeps "is this still open?"
 * answerable from one place. A tool that let the model set the flag directly would let it
 * produce a task that is finished on the board and overdue in the report.
 */
final class ChangeTaskStatusTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly ChangeTaskStatus $changeStatus) {}

    public function name(): string
    {
        return 'change_task_status';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Move a task into another column on its own project board, by column name '
            .'(e.g. "In Progress"). Completion dates follow the column automatically. Refuses '
            .'a column name the project does not have and lists the ones it does.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_id', 'status'],
            'properties' => [
                'task_id' => ['type' => 'integer', 'minimum' => 1],
                'status' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                    'description' => 'The board column to move the task into, by name.',
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
        return Permission::TaskUpdate;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->move($in, $ctx));
    }

    private function move(Arguments $in, AgentContext $ctx): ToolResult
    {
        $taskId = $in->int('task_id');
        $task = $this->resolveTask($taskId, $ctx);

        if (! $task instanceof Task) {
            return $this->notFound(__('task'), $taskId);
        }

        $ctx->assertInWorkspace($task);

        if (! $ctx->allows('changeStatus', $task)) {
            return $this->denied($ctx, __('move task :key to another column', ['key' => $task->key]));
        }

        $project = $task->relationLoaded('project') ? $task->getRelation('project') : null;

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), (int) $task->project_id);
        }

        $name = (string) $in->text('status');
        $status = $this->resolveTaskStatus($project, $name, $ctx);

        if (! $status instanceof TaskStatus) {
            return ToolResult::failed(
                __(':project has no board column called ":name". Its columns are: :columns.', [
                    'project' => $project->name,
                    'name' => self::clip($name, 60),
                    'columns' => implode(', ', $this->taskStatusNames($project, $ctx)),
                ]),
                'unknown_status',
            );
        }

        $from = $task->status()->first();

        if ((int) $task->status_id === (int) $status->getKey()) {
            return ToolResult::ok(
                __('Task :key is already in :status; nothing changed.', [
                    'key' => $task->key,
                    'status' => $status->name,
                ]),
                ['task_id' => (int) $task->getKey(), 'status' => $status->name],
                $task,
            );
        }

        ($this->changeStatus)($task, $status, $ctx->user);

        return ToolResult::ok(
            __('Moved task :key ":title" from :from to :to.', [
                'key' => $task->key,
                'title' => self::clip($task->title, 100),
                'from' => $from?->name ?? __('no column'),
                'to' => $status->name,
            ]),
            [
                'task_id' => (int) $task->getKey(),
                'key' => $task->key,
                'from' => $from?->name,
                'to' => $status->name,
                'category' => $status->category->value,
                'completed' => $task->completed_at !== null,
            ],
            $task,
        );
    }
}
