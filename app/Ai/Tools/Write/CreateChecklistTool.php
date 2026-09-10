<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\CreateChecklistItem;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Task;

/**
 * Add tick boxes to a task.
 *
 * Takes a list rather than one title because that is how the work actually arrives — "break
 * this down into steps" is one intention, and turning it into fifteen tool calls burns the
 * run's call budget on bookkeeping and leaves a half-written checklist behind if the run hits
 * a limit halfway through. One call, one authorization check, one bounded outcome.
 *
 * Checklist items carry no `workspace_id` of their own; they are reachable only through their
 * task, which is why the tenancy question is settled on the task and never re-asked per item.
 * The same is true of authorization: adding a tick box is an edit to the task, so
 * `task.update` on that task is the whole of it.
 */
final class CreateChecklistTool implements AiTool
{
    /**
     * The most items one call may add. A checklist longer than this is not a checklist, and
     * an agent that wants one should be visibly asking twice.
     */
    public const MAX_ITEMS = 50;

    use MutatesThroughActions;

    public function __construct(private readonly CreateChecklistItem $createItem) {}

    public function name(): string
    {
        return 'create_checklist';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Add checklist items to a task, in the order given. At most '.self::MAX_ITEMS
            .' per call; anything beyond that is reported back rather than silently dropped. '
            .'Items are appended after any that already exist.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_id', 'items'],
            'properties' => [
                'task_id' => ['type' => 'integer', 'minimum' => 1],
                'items' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 200,
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                    'description' => 'Checklist item titles, in the order they should appear.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Low;
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
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->add($in, $ctx));
    }

    private function add(Arguments $in, AgentContext $ctx): ToolResult
    {
        $taskId = $in->int('task_id');
        $task = $this->resolveTask($taskId, $ctx);

        if (! $task instanceof Task) {
            return $this->notFound(__('task'), $taskId);
        }

        $ctx->assertInWorkspace($task);

        if ($ctx->cannot(Permission::TaskUpdate, $task)) {
            return $this->denied($ctx, __('change task :key', ['key' => $task->key]));
        }

        $requested = $in->strings('items');
        $accepted = array_slice($requested, 0, self::MAX_ITEMS);
        $capped = count($requested) - count($accepted);

        if ($accepted === []) {
            return ToolResult::failed(
                __('No usable checklist items were given, so task :key was not changed.', ['key' => $task->key]),
                'nothing_to_change',
            );
        }

        $created = [];

        foreach ($accepted as $title) {
            $item = ($this->createItem)($task, mb_substr($title, 0, 255), $ctx->user);

            $created[] = ['id' => (int) $item->getKey(), 'title' => $item->title];
        }

        $summary = __('Added :count checklist item(s) to task :key ":title".', [
            'count' => count($created),
            'key' => $task->key,
            'title' => self::clip($task->title, 80),
        ]);

        if ($capped > 0) {
            $summary .= ' '.__(':count more were not added because one call adds at most :limit — call again for the rest.', [
                'count' => $capped,
                'limit' => self::MAX_ITEMS,
            ]);
        }

        return ToolResult::ok($summary, [
            'task_id' => (int) $task->getKey(),
            'key' => $task->key,
            'created' => $created,
            'created_count' => count($created),
            'capped_count' => $capped,
            'limit' => self::MAX_ITEMS,
        ], $task);
    }
}
