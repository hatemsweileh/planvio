<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\CreateSubtask;
use App\Actions\Tasks\CreateTaskData;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

/**
 * Break a task down: create a task hanging under an existing one.
 *
 * A subtask is an ordinary task with a parent, so it inherits everything that makes
 * {@see CreateTaskTool} safe — `ai_generated` is always true, the reporter is the acting
 * user, an ambiguous date stops the call — and adds one rule of its own: the child lands in
 * the parent's project, never in a project named separately. Letting the model choose both
 * would make "subtask of WEB-42, in the Marketing project" expressible, and the action would
 * refuse it anyway; taking the project from the parent removes the question.
 */
final class CreateSubtaskTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateSubtask $createSubtask) {}

    public function name(): string
    {
        return 'create_subtask';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Create a task underneath an existing one. The subtask is created in the '
            .'parent task\'s project; you cannot put it somewhere else. Dates may be written '
            .'plainly and are read in the workspace timezone.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['parent_task_id', 'title'],
            'properties' => [
                'parent_task_id' => ['type' => 'integer', 'minimum' => 1],
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'description' => ['type' => ['string', 'null'], 'maxLength' => 20000],
                'priority' => ['type' => 'string', 'enum' => ['none', 'low', 'medium', 'high', 'urgent']],
                'assignee_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'due_date' => ['type' => ['string', 'null'], 'maxLength' => 64],
                'estimate_minutes' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 100000],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Medium;
    }

    public function permission(): ?Permission
    {
        return Permission::TaskCreate;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->create($in, $ctx));
    }

    private function create(Arguments $in, AgentContext $ctx): ToolResult
    {
        $parentId = $in->int('parent_task_id');
        $parent = $this->resolveTask($parentId, $ctx);

        if (! $parent instanceof Task) {
            return $this->notFound(__('task'), $parentId);
        }

        $ctx->assertInWorkspace($parent);

        $project = $parent->relationLoaded('project') ? $parent->getRelation('project') : null;

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), (int) $parent->project_id);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::TaskCreate, $project)) {
            return $this->denied($ctx, __('create tasks in :project', ['project' => $project->name]));
        }

        $assignee = null;

        if ($in->filled('assignee_id')) {
            $assigneeId = $in->int('assignee_id');
            $assignee = $this->resolveUser($assigneeId, $ctx);

            if (! $assignee instanceof User) {
                return $this->notFound(__('workspace member'), $assigneeId);
            }

            if ($ctx->cannot(Permission::TaskAssign, $this->unsavedTaskIn($project))) {
                return $this->denied($ctx, __('assign tasks in :project', ['project' => $project->name]));
            }
        }

        $dueDate = $this->dateArgument($in, 'due_date', $ctx);

        if ($dueDate instanceof ToolResult) {
            return $dueDate;
        }

        $subtask = ($this->createSubtask)($parent, new CreateTaskData(
            project: $project,
            actor: $ctx->user,
            title: (string) $in->text('title'),
            description: $in->text('description'),
            priority: $in->enum('priority', Priority::class) ?? Priority::Medium,
            assignee: $assignee,
            dueDate: $dueDate,
            estimateMinutes: $in->nullableInt('estimate_minutes'),
            aiGenerated: true,
        ));

        return ToolResult::ok(
            __('Created subtask :key ":title" under :parent in :project.', [
                'key' => $subtask->key,
                'title' => self::clip($subtask->title, 100),
                'parent' => $parent->key,
                'project' => $project->name,
            ]),
            [
                'task_id' => (int) $subtask->getKey(),
                'key' => $subtask->key,
                'title' => $subtask->title,
                'parent_task_id' => (int) $parent->getKey(),
                'parent_key' => $parent->key,
                'project_id' => (int) $project->getKey(),
                'assignee' => $assignee?->name,
                'due_date' => $subtask->due_date?->format('Y-m-d'),
                'ai_generated' => true,
            ],
            $subtask,
        );
    }
}
