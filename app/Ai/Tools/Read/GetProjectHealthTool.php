<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\MilestoneStatus;
use App\Enums\Permission;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Task;
use App\Services\ProjectHealthCalculator;
use App\Services\WorkloadRow;
use App\Services\WorkloadService;
use Illuminate\Database\Eloquent\Builder;

/**
 * What {@see ProjectHealthCalculator} concludes about a project, and the numbers it read.
 *
 * The shape of the result is the whole point. `stored_health` is a fact — the value the
 * system has recorded on the row. `facts` are counts anyone can re-derive: how many open
 * tasks are past due, how many milestones are late, how concentrated the remaining work is.
 * `assessment` is explicitly labelled as an assessment, carries the signals it was derived
 * from, and comes with a note saying so.
 *
 * That separation exists because the system prompt asks the model to distinguish "figures
 * the system actually recorded" from its own analysis. A tool that returned
 * `{"health": "at_risk"}` and nothing else would make that instruction impossible to follow:
 * the model would have no way to tell a stored value from a computed one, and no numbers to
 * show a person who asks why.
 */
final class GetProjectHealthTool extends ReadTool
{
    public function __construct(
        private readonly ProjectHealthCalculator $health = new ProjectHealthCalculator,
        private readonly WorkloadService $workload = new WorkloadService,
    ) {}

    public function name(): string
    {
        return 'get_project_health';
    }

    public function description(): string
    {
        return 'Assess one project\'s health and return the facts behind it: overdue task '
            .'count and oldest overdue due date, delayed milestones, and how much of the open '
            .'work sits with one person. The assessment is labelled as an assessment; '
            .'stored_health is the value recorded on the project.';
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

        $today = $ctx->today();
        $assessment = $this->health->calculate($project, $today);

        $facts = [
            'tasks' => $this->taskFacts($ctx, $project),
            'workload_concentration' => $this->concentration($project),
        ];

        if ($ctx->can(Permission::MilestoneView, $project)) {
            $facts['milestones'] = $this->milestoneFacts($ctx, $project);
        } else {
            $facts['milestones_note'] = __('ai.tools.notes.milestones_withheld');
        }

        $data = [
            'project' => [
                'id' => (int) $project->getKey(),
                'key' => (string) $project->key,
                'name' => (string) $project->name,
                'is_archived' => (bool) $project->is_archived,
                'target_date' => self::day($project->target_date),
            ],
            'stored_health' => $assessment->health->value,
            'health_set_manually' => $assessment->manual,
            'health_note' => self::excerpt($project->health_note, 200),
            'facts' => $facts,
            'assessment' => [
                'kind' => 'assessment',
                'computed_health' => $assessment->computed->value,
                'overrides_computed' => $assessment->overridesComputed(),
                'derived_by' => 'Planvio ProjectHealthCalculator',
                'signals' => $assessment->reasons,
            ],
            'note' => __('ai.tools.notes.assessment'),
        ];

        return ToolResult::ok(
            __('ai.tools.summary.health', [
                'key' => (string) $project->key,
                'name' => (string) $project->name,
                'stored' => $assessment->health->value,
                'computed' => $assessment->computed->value,
                'signals' => count($assessment->reasons),
            ]),
            $data,
            $project,
        );
    }

    /**
     * @return array{total: int, open: int, completed: int, overdue: int, unassigned_open: int, oldest_overdue_due_date: string|null}
     */
    private function taskFacts(AgentContext $ctx, Project $project): array
    {
        $oldest = $this->tasksOf($ctx, $project)
            ->overdue($ctx->today())
            ->orderBy('tasks.due_date')
            ->value('tasks.due_date');

        return [
            'total' => $this->tasksOf($ctx, $project)->count(),
            'open' => $this->tasksOf($ctx, $project)->open()->count(),
            'completed' => $this->tasksOf($ctx, $project)->completed()->count(),
            'overdue' => $this->tasksOf($ctx, $project)->overdue($ctx->today())->count(),
            'unassigned_open' => $this->tasksOf($ctx, $project)->open()->unassigned()->count(),
            'oldest_overdue_due_date' => self::day($oldest),
        ];
    }

    /**
     * A milestone is late when it says so, or when it is still open and its due date has
     * passed — the same rule {@see ProjectHealthCalculator} applies, so the fact and the
     * signal cannot disagree.
     *
     * @return array{total: int, delayed: int, open: int, earliest_delayed_due_date: string|null}
     */
    private function milestoneFacts(AgentContext $ctx, Project $project): array
    {
        $earliest = $this->delayedMilestones($ctx, $project)
            ->orderBy('milestones.due_date')
            ->value('milestones.due_date');

        return [
            'total' => $this->milestonesOf($ctx, $project)->count(),
            'open' => $this->milestonesOf($ctx, $project)->open()->count(),
            'delayed' => $this->delayedMilestones($ctx, $project)->count(),
            'earliest_delayed_due_date' => self::day($earliest),
        ];
    }

    /**
     * The busiest assignee's share of the remaining open work — a project one person away
     * from stopping, even while nothing is late yet.
     *
     * @return array{open_tasks: int, busiest_user_id: int|null, busiest_user_name: string|null, assigned_open_tasks: int, share: float|null, unassigned_open_tasks: int}
     */
    private function concentration(Project $project): array
    {
        $rows = $this->workload->forProject($project);
        $busiest = WorkloadService::busiest($rows);

        $open = 0;
        $unassigned = 0;

        foreach ($rows as $row) {
            $open += $row->open;

            if ($row->isUnassigned()) {
                $unassigned = $row->open;
            }
        }

        return [
            'open_tasks' => $open,
            'busiest_user_id' => $busiest?->userId,
            'busiest_user_name' => $busiest?->userName,
            'assigned_open_tasks' => $busiest instanceof WorkloadRow ? $busiest->open : 0,
            'share' => $busiest instanceof WorkloadRow && $open > 0
                ? round($busiest->open / $open, 4)
                : null,
            'unassigned_open_tasks' => $unassigned,
        ];
    }

    /**
     * @return Builder<Task>
     */
    private function tasksOf(AgentContext $ctx, Project $project): Builder
    {
        return Task::query()->forWorkspace($ctx->workspace)->forProject($project);
    }

    /**
     * @return Builder<Milestone>
     */
    private function milestonesOf(AgentContext $ctx, Project $project): Builder
    {
        return Milestone::query()->forWorkspace($ctx->workspace)->forProject($project);
    }

    /**
     * @return Builder<Milestone>
     */
    private function delayedMilestones(AgentContext $ctx, Project $project): Builder
    {
        $today = $ctx->today()->toDateString();

        $openStatuses = array_values(array_map(
            static fn (MilestoneStatus $status): string => $status->value,
            array_filter(
                MilestoneStatus::cases(),
                static fn (MilestoneStatus $status): bool => $status->isOpen(),
            ),
        ));

        return $this->milestonesOf($ctx, $project)
            ->where(static function (Builder $late) use ($openStatuses, $today): void {
                $late
                    ->where('milestones.status', MilestoneStatus::Delayed->value)
                    ->orWhere(static function (Builder $overdue) use ($openStatuses, $today): void {
                        $overdue
                            ->whereIn('milestones.status', $openStatuses)
                            ->whereNull('milestones.completed_at')
                            ->whereNotNull('milestones.due_date')
                            ->where('milestones.due_date', '<', $today);
                    });
            });
    }
}
