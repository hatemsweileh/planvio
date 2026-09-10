<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\BudgetService;
use Illuminate\Database\Eloquent\Builder;

/**
 * Everything about one project the acting user is allowed to see.
 *
 * The permission is checked twice on purpose. The project is resolved through
 * {@see ReadTool::visibleProjects()}, which is the query-side rule, and then judged again by
 * `ProjectPolicy::view()` through the Gate — the authority, which re-resolves membership from
 * the record's own `workspace_id` without trusting any scope (ARCHITECTURE.md section 3).
 *
 * The sections below the summary are individually gated: milestones need `milestone.view`
 * and the budget needs `budget.view` for this project. A section the caller may not read is
 * omitted *and said to be omitted*, so the model reports a gap rather than inferring that a
 * project has no milestones or no budget.
 */
final class GetProjectTool extends ReadTool
{
    private const MAX_MILESTONES = 10;

    private const MAX_MEMBERS = 12;

    public function __construct(
        private readonly BudgetService $budgets = new BudgetService,
    ) {}

    public function name(): string
    {
        return 'get_project';
    }

    public function description(): string
    {
        return 'Read one project in full: status, health, dates, progress, task counts, '
            .'milestones, members and — where the acting user may see it — budget. '
            .'Accepts a project id or a project key such as WEB.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['project'],
            'properties' => [
                'project' => [
                    'type' => ['integer', 'string'],
                    'description' => 'The project id, or its key such as WEB.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $reference = $args['project'];
        $reference = is_int($reference) ? $reference : (string) $reference;

        $project = $this->resolveProject($ctx, $reference);

        if ($project === null) {
            return $this->notFound('project', $reference);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::ProjectView, $project)) {
            return $this->denied('project');
        }

        $project->loadMissing(['status:id,name,category', 'owner:id,name', 'manager:id,name']);

        $timezone = $ctx->resolvedTimezone();
        $counts = $this->taskCounts($ctx, $project);

        $data = [
            'id' => (int) $project->getKey(),
            'key' => (string) $project->key,
            'name' => (string) $project->name,
            'type' => $project->type?->value,
            'status' => $project->status?->name,
            'status_category' => $project->status?->category?->value,
            'stored_health' => $project->health?->value,
            'health_set_manually' => (bool) $project->health_set_manually,
            'health_note' => self::excerpt($project->health_note, 200),
            'priority' => $project->priority?->value,
            'progress' => (int) $project->progress,
            'description' => self::excerpt($project->description, 600),
            'owner' => $project->owner?->name,
            'manager' => $project->manager?->name,
            'client_name' => $project->client_name,
            'department' => $project->department,
            'start_date' => self::day($project->start_date),
            'target_date' => self::day($project->target_date),
            'completed_at' => self::moment($project->completed_at, $timezone),
            'is_archived' => (bool) $project->is_archived,
            'archived_at' => self::moment($project->archived_at, $timezone),
            'tasks' => $counts,
            'members' => $this->members($project),
        ];

        if ($ctx->can(Permission::MilestoneView, $project)) {
            $data['milestones'] = $this->milestones($ctx, $project);
        } else {
            $data['milestones_note'] = __('ai.tools.notes.milestones_withheld');
        }

        if ($ctx->can(Permission::BudgetView, $project)) {
            $budget = $this->budgets->forProject($project);

            $data['budget'] = $budget->toArray();
            $data['budget']['note'] = __('ai.tools.notes.labour_not_costed');
        } else {
            $data['budget_note'] = __('ai.tools.notes.budget_withheld');
        }

        return ToolResult::ok(
            __('ai.tools.summary.project', [
                'key' => (string) $project->key,
                'name' => (string) $project->name,
                'status' => $project->status?->name ?? '-',
                'health' => $project->health?->value ?? '-',
                'open' => $counts['open'],
                'total' => $counts['total'],
                'overdue' => $counts['overdue'],
            ]),
            $data,
            $project,
        );
    }

    /**
     * Five bounded counts rather than one hand-written aggregate: each is an existing scope,
     * so nothing here composes SQL and the definitions of "open" and "overdue" stay the same
     * ones the rest of the product uses.
     *
     * @return array{total: int, open: int, completed: int, overdue: int, unassigned_open: int}
     */
    private function taskCounts(AgentContext $ctx, Project $project): array
    {
        return [
            'total' => $this->tasksOf($ctx, $project)->count(),
            'open' => $this->tasksOf($ctx, $project)->open()->count(),
            'completed' => $this->tasksOf($ctx, $project)->completed()->count(),
            'overdue' => $this->tasksOf($ctx, $project)->overdue($ctx->today())->count(),
            'unassigned_open' => $this->tasksOf($ctx, $project)->open()->unassigned()->count(),
        ];
    }

    /**
     * @return Builder<Task>
     */
    private function tasksOf(AgentContext $ctx, Project $project): Builder
    {
        return Task::query()
            ->forWorkspace($ctx->workspace)
            ->forProject($project);
    }

    /**
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>, note?: string}
     */
    private function members(Project $project): array
    {
        $total = ProjectMember::query()->forProject($project)->count();

        $memberships = ProjectMember::query()
            ->forProject($project)
            ->with('user:id,name,job_title')
            ->limit(self::MAX_MEMBERS)
            ->get();

        $rows = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if ($user === null) {
                continue;
            }

            $rows[] = [
                'user_id' => (int) $user->getKey(),
                'name' => (string) $user->name,
                'role' => $membership->role?->value,
                'job_title' => $user->job_title,
            ];
        }

        $members = $this->page($rows, $total, 'list');

        if ($total === 0) {
            $members['note'] = __('ai.tools.notes.no_explicit_members');
        }

        return $members;
    }

    /**
     * @return array{returned: int, total: int, omitted: int, list: list<array<string, mixed>>}
     */
    private function milestones(AgentContext $ctx, Project $project): array
    {
        $total = Milestone::query()
            ->forWorkspace($ctx->workspace)
            ->forProject($project)
            ->count();

        $milestones = Milestone::query()
            ->forWorkspace($ctx->workspace)
            ->forProject($project)
            ->ordered()
            ->limit(self::MAX_MILESTONES)
            ->get();

        $today = $ctx->today()->toDateString();
        $rows = [];

        foreach ($milestones as $milestone) {
            $due = self::day($milestone->due_date);

            $rows[] = [
                'id' => (int) $milestone->getKey(),
                'name' => (string) $milestone->name,
                'status' => $milestone->status?->value,
                'due_date' => $due,
                'is_overdue' => $due !== null && $milestone->completed_at === null && $due < $today,
                'progress' => (int) $milestone->progress,
            ];
        }

        return $this->page($rows, $total, 'list');
    }
}
