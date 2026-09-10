<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Dependencies\CreateDependency;
use App\Actions\Dependencies\DependencyCycleDetected;
use App\Actions\Dependencies\SelfDependency;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\DependencyType;
use App\Enums\Permission;
use App\Models\Task;

/**
 * Declare that one task waits for another.
 *
 * A cycle is refused by {@see CreateDependency} — nothing inside a ring can ever start, and a
 * timeline has no order to draw — and the refusal is reported here exactly as it came back,
 * naming the chain the new link would have closed. What deliberately does not happen is a
 * retry: not with the edge reversed, not with a different type, not after quietly deleting
 * something in the way. A cycle means the model's plan is wrong, and the useful thing to do
 * with that is tell the person, not route around it.
 *
 * Both tasks are resolved in the bound workspace first, so an id from another tenant is
 * refused before the action ever sees it — an edge carries a single `workspace_id` and
 * writing one across two would put a foreign task id in this workspace's graph.
 */
final class CreateDependencyTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateDependency $createDependency) {}

    public function name(): string
    {
        return 'create_dependency';
    }

    public function group(): string
    {
        return 'dependencies';
    }

    public function description(): string
    {
        return 'Record that one task depends on another. Refuses any link that would create '
            .'a cycle and names the chain it would close. Dependencies may cross projects '
            .'but never workspaces.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_id', 'depends_on_task_id'],
            'properties' => [
                'task_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The task that waits.',
                ],
                'depends_on_task_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The task it waits for.',
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => ['finish_to_start', 'blocks', 'relates_to'],
                    'description' => 'Defaults to finish_to_start.',
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
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->link($in, $ctx));
    }

    private function link(Arguments $in, AgentContext $ctx): ToolResult
    {
        $taskId = $in->int('task_id');
        $task = $this->resolveTask($taskId, $ctx);

        if (! $task instanceof Task) {
            return $this->notFound(__('task'), $taskId);
        }

        $dependsOnId = $in->int('depends_on_task_id');
        $dependsOn = $this->resolveTask($dependsOnId, $ctx);

        if (! $dependsOn instanceof Task) {
            return $this->notFound(__('task'), $dependsOnId);
        }

        $ctx->assertInWorkspace($task);
        $ctx->assertInWorkspace($dependsOn);

        // The edge is a change to the waiting task, so that is the record whose policy decides.
        if ($ctx->cannot(Permission::TaskUpdate, $task)) {
            return $this->denied($ctx, __('change task :key', ['key' => $task->key]));
        }

        $type = $in->enum('type', DependencyType::class) ?? DependencyType::FinishToStart;

        try {
            $dependency = ($this->createDependency)($task, $dependsOn, $ctx->user, $type);
        } catch (DependencyCycleDetected $e) {
            return ToolResult::failed(
                __('That link would close a loop (:path), so it was not created. Nothing was changed — the plan needs a different order, not another attempt.', [
                    'path' => self::clip($e->pathLabel, 300),
                ]),
                'dependency_cycle',
                ['cycle' => array_slice($e->path, 0, 25)],
            );
        } catch (SelfDependency) {
            return ToolResult::failed(
                __('Task :key cannot depend on itself.', ['key' => $task->key]),
                'self_dependency',
            );
        }

        return ToolResult::ok(
            __('Task :task now depends on :dependsOn (:type).', [
                'task' => $task->key,
                'dependsOn' => $dependsOn->key,
                'type' => $type->value,
            ]),
            [
                'dependency_id' => (int) $dependency->getKey(),
                'task_id' => (int) $task->getKey(),
                'task_key' => $task->key,
                'depends_on_task_id' => (int) $dependsOn->getKey(),
                'depends_on_key' => $dependsOn->key,
                'type' => $dependency->type->value,
            ],
            $dependency,
        );
    }
}
