<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Enums\ProjectHealth;
use App\Models\Project;
use App\Models\Task;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * The orientation read: where this workspace stands, and what is on the acting user's plate.
 *
 * Everything counted here is counted over {@see ReadTool::visibleProjects()}, so the same
 * call made by an owner and by a guest returns two different — and each time correct —
 * pictures. A guest's "workspace overview" is an overview of the one project they are in.
 *
 * The health figures are the values *stored* on the projects, which is a fact about the
 * records. They are labelled `stored_health` for that reason: the calculated assessment,
 * with the signals behind it, is `get_project_health`, and conflating the two would let the
 * model present an inference as something the system recorded.
 */
final class GetWorkspaceOverviewTool extends ReadTool
{
    private const MAX_PROJECTS = 8;

    private const MAX_AT_RISK = 6;

    private const MAX_TASKS = 8;

    public function name(): string
    {
        return 'get_workspace_overview';
    }

    public function description(): string
    {
        return 'Summarise the bound workspace: project counts, the most recently active '
            .'projects, the projects whose recorded health is not on track, and the acting '
            .'user\'s own open and overdue tasks. Counts cover only what the acting user may see.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 25,
                    'description' => 'Maximum rows in each of the three lists.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::WorkspaceView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $workspace = $ctx->workspace;

        $ctx->assertInWorkspace($workspace);

        if ($ctx->cannot(Permission::WorkspaceView, $workspace)) {
            return $this->denied('workspace');
        }

        $limit = is_int($args['limit'] ?? null) ? max(1, $args['limit']) : self::MAX_PROJECTS;
        $today = $ctx->today();

        $activeCount = $this->visibleProjects($ctx)->active()->count();
        $archivedCount = $this->visibleProjects($ctx)->archived()->count();
        $atRiskCount = $this->visibleProjects($ctx)
            ->active()
            ->whereIn('projects.health', [ProjectHealth::AtRisk->value, ProjectHealth::OffTrack->value])
            ->count();

        $myOpen = $this->myTasks($ctx)->open()->count();
        $myOverdue = $this->myTasks($ctx)->overdue($today)->count();
        $unassignedOpen = $this->workspaceTasks($ctx)->open()->unassigned()->count();

        $data = [
            'workspace' => [
                'name' => (string) $workspace->name,
                'timezone' => $ctx->resolvedTimezone(),
                'today' => $today->toDateString(),
                'currency' => (string) $workspace->currency,
                'week_starts_on' => (int) $workspace->week_starts_on,
            ],
            'acting_user' => [
                'id' => $ctx->userId(),
                'name' => (string) $ctx->user->name,
                'workspace_role' => $ctx->workspaceRole()?->value,
            ],
            'counts' => [
                'active_projects' => $activeCount,
                'archived_projects' => $archivedCount,
                'projects_not_on_track' => $atRiskCount,
                'my_open_tasks' => $myOpen,
                'my_overdue_tasks' => $myOverdue,
                'unassigned_open_tasks' => $unassignedOpen,
                'members' => WorkspaceMember::query()->forWorkspace($workspace)->count(),
            ],
            'active_projects' => $this->projectRows(
                $this->visibleProjects($ctx)->active()->orderByDesc('projects.updated_at'),
                $activeCount,
                min($limit, self::MAX_PROJECTS),
            ),
            'projects_not_on_track' => $this->projectRows(
                $this->visibleProjects($ctx)
                    ->active()
                    ->whereIn('projects.health', [ProjectHealth::AtRisk->value, ProjectHealth::OffTrack->value])
                    ->orderByDesc('projects.updated_at'),
                $atRiskCount,
                min($limit, self::MAX_AT_RISK),
            ),
            'my_open_tasks' => $this->myTaskRows($ctx, $myOpen, min($limit, self::MAX_TASKS)),
            'health_note' => __('ai.tools.notes.assessment'),
        ];

        $data = $this->fit($data, 'active_projects');

        return ToolResult::ok(
            __('ai.tools.summary.overview', [
                'workspace' => (string) $workspace->name,
                'active' => $activeCount,
                'at_risk' => $atRiskCount,
                'open' => $myOpen,
                'overdue' => $myOverdue,
            ]),
            $data,
            $workspace,
        );
    }

    /**
     * @param Builder<Project> $query
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>}
     */
    private function projectRows(Builder $query, int $total, int $limit): array
    {
        $projects = $query
            ->with(['status:id,name,category'])
            ->withCount([
                'tasks as open_task_count' => static fn (Builder $tasks): Builder => $tasks->whereNull('tasks.completed_at'),
            ])
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [
                'id' => (int) $project->getKey(),
                'key' => (string) $project->key,
                'name' => (string) $project->name,
                'status' => $project->status?->name,
                'stored_health' => $project->health?->value,
                'progress' => (int) $project->progress,
                'open_tasks' => (int) ($project->getAttribute('open_task_count') ?? 0),
                'target_date' => self::day($project->target_date),
            ];
        }

        return $this->page($rows, $total, 'list');
    }

    /**
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>}
     */
    private function myTaskRows(AgentContext $ctx, int $total, int $limit): array
    {
        $tasks = $this->myTasks($ctx)
            ->open()
            ->with(['project:id,key', 'status:id,name,category'])
            ->orderBy('tasks.due_date')
            ->orderByDesc('tasks.updated_at')
            ->limit($limit)
            ->get();

        $today = $ctx->today()->toDateString();
        $rows = [];

        foreach ($tasks as $task) {
            $due = self::day($task->due_date);

            $rows[] = [
                'id' => (int) $task->getKey(),
                'key' => (string) $task->key,
                'title' => self::clip((string) $task->title, 120),
                'status' => $task->status?->name,
                'priority' => $task->priority?->value,
                'due_date' => $due,
                'is_overdue' => $due !== null && $due < $today,
            ];
        }

        return $this->page($rows, $total, 'list');
    }

    /**
     * @return Builder<Task>
     */
    private function workspaceTasks(AgentContext $ctx): Builder
    {
        return Task::query()
            ->forWorkspace($ctx->workspace)
            ->whereIn('tasks.project_id', $this->visibleProjectIds($ctx));
    }

    /**
     * @return Builder<Task>
     */
    private function myTasks(AgentContext $ctx): Builder
    {
        return $this->workspaceTasks($ctx)->assignedTo($ctx->userId());
    }
}
