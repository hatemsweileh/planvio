<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Tasks\CreateTask;
use App\Actions\Tasks\CreateTaskData;
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
use App\Services\DateResolver;
use Carbon\CarbonImmutable;

/**
 * Put a new task on a project board, as the acting user.
 *
 * Three properties are worth stating because each of them is a decision, not an accident:
 *
 * **`ai_generated` is always true.** A person reading their board a month later must be able
 * to tell which cards a human raised and which the agent did. The column is not an argument
 * the model can set, so there is no call shape that produces an AI task indistinguishable
 * from a human one.
 *
 * **The reporter is the acting user.** `CreateTask` defaults the reporter to the actor, and
 * the actor is the human the run is executing for. The task is therefore attributed to a real
 * person who holds `task.create` in this project — which is the only authority in the system,
 * as AI_SECURITY.md puts it, because the AI has none of its own.
 *
 * **An ambiguous date stops the call.** "Next Friday" resolves to different days for
 * different readers, so {@see DateResolver} refuses it and this tool returns a
 * question instead of a task. Creating the card with a plausible-looking deadline would
 * produce work somebody misses and nobody can explain.
 *
 * Assigning at creation is authorised as an assignment, not as part of creation: a plain
 * member holds `task.create` but not `task.assign`, and handing work to a colleague through
 * the create form must not be the way around that.
 */
final class CreateTaskTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateTask $createTask) {}

    public function name(): string
    {
        return 'create_task';
    }

    public function group(): string
    {
        return 'tasks';
    }

    public function description(): string
    {
        return 'Create a task in a project. Dates may be written plainly ("tomorrow", '
            .'"end of month", "2026-09-30") and are read in the workspace timezone; a phrase '
            .'with more than one possible meaning is refused rather than guessed. Refuses an '
            .'assignee who is not in the workspace, a board column belonging to another '
            .'project, and any project the acting user cannot create tasks in.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title'],
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The project to create the task in. Defaults to the project this run is focused on.',
                ],
                'title' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'description' => 'What the task is, as a short imperative phrase.',
                ],
                'description' => [
                    'type' => ['string', 'null'],
                    'maxLength' => 20000,
                    'description' => 'Optional detail. Plain text or simple HTML; it is sanitised on the way in.',
                ],
                'status' => [
                    'type' => 'string',
                    'maxLength' => 100,
                    'description' => 'Board column name, e.g. "To Do". Defaults to the project default column.',
                ],
                'priority' => [
                    'type' => 'string',
                    'enum' => ['none', 'low', 'medium', 'high', 'urgent'],
                    'description' => 'Defaults to medium.',
                ],
                'assignee_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'User id of the person to assign. Must already be a member of the workspace.',
                ],
                'milestone_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Milestone to file the task under. Must belong to the same project.',
                ],
                'start_date' => [
                    'type' => ['string', 'null'],
                    'maxLength' => 64,
                    'description' => 'When work starts. A date or a plain phrase.',
                ],
                'due_date' => [
                    'type' => ['string', 'null'],
                    'maxLength' => 64,
                    'description' => 'When the task is due. A date or a plain phrase.',
                ],
                'estimate_minutes' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 0,
                    'maximum' => 100000,
                    'description' => 'Effort estimate in minutes.',
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
        $project = $this->project($in, $ctx);

        if ($project instanceof ToolResult) {
            return $project;
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::TaskCreate, $project)) {
            return $this->denied($ctx, __('create tasks in :project', ['project' => $project->name]));
        }

        $status = $this->status($in, $project, $ctx);

        if ($status instanceof ToolResult) {
            return $status;
        }

        $assignee = $this->assignee($in, $project, $ctx);

        if ($assignee instanceof ToolResult) {
            return $assignee;
        }

        $milestone = $this->milestone($in, $project, $ctx);

        if ($milestone instanceof ToolResult) {
            return $milestone;
        }

        $startDate = $this->dateArgument($in, 'start_date', $ctx);

        if ($startDate instanceof ToolResult) {
            return $startDate;
        }

        $dueDate = $this->dateArgument($in, 'due_date', $ctx);

        if ($dueDate instanceof ToolResult) {
            return $dueDate;
        }

        $task = ($this->createTask)(new CreateTaskData(
            project: $project,
            actor: $ctx->user,
            title: (string) $in->text('title'),
            description: $in->text('description'),
            status: $status,
            priority: $in->enum('priority', Priority::class) ?? Priority::Medium,
            assignee: $assignee,
            milestone: $milestone,
            startDate: $startDate,
            dueDate: $dueDate,
            estimateMinutes: $in->nullableInt('estimate_minutes'),
            aiGenerated: true,
        ));

        return ToolResult::ok($this->summary($task, $project, $assignee, $dueDate), [
            'task_id' => (int) $task->getKey(),
            'key' => $task->key,
            'title' => $task->title,
            'project_id' => (int) $project->getKey(),
            'project' => $project->name,
            'status' => $task->status?->name,
            'priority' => $task->priority->value,
            'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
            'assignee' => $assignee?->name,
            'milestone_id' => $task->milestone_id === null ? null : (int) $task->milestone_id,
            'start_date' => $task->start_date?->toDateString(),
            'due_date' => $task->due_date?->toDateString(),
            'estimate_minutes' => $task->estimate_minutes,
            'ai_generated' => true,
            'created_by' => $ctx->user->name,
        ], $task);
    }

    private function project(Arguments $in, AgentContext $ctx): Project|ToolResult
    {
        $id = $in->nullableInt('project_id');

        if ($id === null) {
            return $ctx->project ?? $this->projectRequired();
        }

        return $this->resolveProject($id, $ctx) ?? $this->notFound(__('project'), $id);
    }

    private function status(Arguments $in, Project $project, AgentContext $ctx): TaskStatus|ToolResult|null
    {
        $name = $in->text('status');

        if ($name === null) {
            return null;
        }

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

    private function assignee(Arguments $in, Project $project, AgentContext $ctx): User|ToolResult|null
    {
        $id = $in->nullableInt('assignee_id');

        if ($id === null) {
            return null;
        }

        $user = $this->resolveUser($id, $ctx);

        if (! $user instanceof User) {
            return $this->notFound(__('workspace member'), $id);
        }

        // Authorised as an assignment, because that is what it is: `task.create` alone must
        // not become a way to hand work to a colleague.
        if ($ctx->cannot(Permission::TaskAssign, $this->unsavedTaskIn($project))) {
            return $this->denied($ctx, __('assign tasks in :project', ['project' => $project->name]));
        }

        return $user;
    }

    private function milestone(Arguments $in, Project $project, AgentContext $ctx): Milestone|ToolResult|null
    {
        $id = $in->nullableInt('milestone_id');

        if ($id === null) {
            return null;
        }

        $milestone = $this->resolveMilestone($id, $ctx);

        if (! $milestone instanceof Milestone) {
            return $this->notFound(__('milestone'), $id);
        }

        if ((int) $milestone->project_id !== (int) $project->getKey()) {
            return ToolResult::failed(
                __('Milestone :milestone belongs to another project, so the task was not created.', [
                    'milestone' => $milestone->name,
                ]),
                'milestone_not_in_project',
            );
        }

        return $milestone;
    }

    private function summary(Task $task, Project $project, ?User $assignee, ?CarbonImmutable $dueDate): string
    {
        $facts = [];

        if ($task->status?->name !== null) {
            $facts[] = __('column :name', ['name' => $task->status->name]);
        }

        if ($assignee instanceof User) {
            $facts[] = __('assigned to :name', ['name' => $assignee->name]);
        }

        if ($dueDate instanceof CarbonImmutable) {
            $facts[] = __('due :date', ['date' => $dueDate->toDateString()]);
        }

        $created = __('Created task :key ":title" in :project.', [
            'key' => $task->key,
            'title' => self::clip($task->title, 120),
            'project' => $project->name,
        ]);

        return $facts === [] ? $created : $created.' '.ucfirst(implode(', ', $facts)).'.';
    }
}
