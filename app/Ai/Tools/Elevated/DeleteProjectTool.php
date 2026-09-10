<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Actions\Projects\DeleteProject;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\Arguments;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Activity;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\WikiPage;

/**
 * `delete_project` — soft-delete one project and take everything under it out of reach.
 *
 * The most consequential call in the registry. `DeleteProject` soft-deletes, so the rows
 * survive and a restore is possible, but nothing beneath the project stays reachable in the
 * meantime: every route to its tasks, milestones, wiki pages and time entries goes through
 * the project.
 *
 * It is on the unwaivable approval list and no `AiPolicy` can take it off — not in autonomous
 * mode, not with `max_risk` raised, not with the tool named in `allowed_tools`. That is
 * enforced twice: by the agent runner's approval gate before the call, and again here in
 * {@see ElevatedTool::approvalGate()} against the recorded `ai_tool_runs` row, so an
 * execution path that bypasses the runner still deletes nothing.
 *
 * One project per call, always. There is no "delete all archived projects" shape and there
 * will not be one: a person has to see and approve each name.
 */
final class DeleteProjectTool extends ElevatedTool
{
    public function __construct(private readonly DeleteProject $deleteProject) {}

    public function name(): string
    {
        return 'delete_project';
    }

    public function group(): string
    {
        return 'projects';
    }

    public function description(): string
    {
        return 'Delete one project. It is archived and moved to the trash, and its tasks, '
            .'milestones, wiki pages and time entries stop being reachable. Always requires a '
            .'human approval, in every mode and under every policy. One project per call — '
            .'state what it would take with it before proposing it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['project_id'],
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The project to delete. One per call.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Destructive;
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectDelete;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle(
            $args,
            $ctx,
            fn (Arguments $in, AgentContext $ctx): ToolResult => $this->delete($args, $in, $ctx),
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array
    {
        $id = $args['project_id'] ?? null;
        $project = $this->resolveProject(is_numeric($id) ? (int) $id : 0, $ctx);

        return $project instanceof Project ? $this->countsFor($project, $ctx) : [];
    }

    /**
     * @param array<string, mixed> $args the raw call, for the approval key
     */
    private function delete(array $args, Arguments $in, AgentContext $ctx): ToolResult
    {
        $id = $in->int('project_id');
        $project = $this->resolveProject($id, $ctx);

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $id);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::ProjectDelete, $project)) {
            return $this->denied($ctx, __('delete :name', ['name' => $project->name]));
        }

        // Nothing past this line runs without a recorded human approval, whatever the mode
        // and whatever any policy says.
        $blocked = $this->approvalGate($args, $ctx, $project);

        if ($blocked instanceof ToolResult) {
            return $blocked;
        }

        // Counted before the delete. Afterwards these queries describe a world that no longer
        // exists, and the summary read back to the user would understate what happened.
        $facts = $this->countsFor($project, $ctx);

        ($this->deleteProject)($project, $ctx->user);

        return ToolResult::ok(
            __('Deleted :key ":name". :tasks tasks, :milestones milestones and :wiki wiki pages went with it. It is in the trash and can be restored.', [
                'key' => $project->key,
                'name' => self::clip($project->name, 80),
                'tasks' => $facts['tasks'],
                'milestones' => $facts['milestones'],
                'wiki' => $facts['wiki_pages'],
            ]),
            $facts,
            $project,
        );
    }

    /**
     * Everything that becomes unreachable, as aggregates.
     *
     * Eight `count()` statements against indexed columns, no collections hydrated.
     * `activities` is in the list because the project's history is what people are most
     * surprised to lose sight of, and it is the number that makes the card honest.
     *
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    private function countsFor(Project $project, AgentContext $ctx): array
    {
        $projectId = (int) $project->getKey();

        return $ctx->bindWorkspace(static fn (): array => [
            'project' => (string) $project->name,
            'key' => (string) $project->key,
            'tasks' => Task::query()->where('project_id', $projectId)->count(),
            'open_tasks' => Task::query()->where('project_id', $projectId)->open()->count(),
            'milestones' => Milestone::query()->where('project_id', $projectId)->count(),
            'members' => ProjectMember::query()->where('project_id', $projectId)->count(),
            'time_entries' => TimeEntry::query()->where('project_id', $projectId)->count(),
            'wiki_pages' => WikiPage::query()->where('project_id', $projectId)->count(),
            'activities' => Activity::query()->where('project_id', $projectId)->count(),
        ]);
    }
}
