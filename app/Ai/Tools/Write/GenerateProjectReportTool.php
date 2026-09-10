<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Milestone;
use App\Models\Project;
use App\Services\BudgetService;
use App\Services\DateRange;
use App\Services\ProjectHealthCalculator;
use App\Services\ProjectProgressCalculator;
use App\Services\TimeReportService;
use App\Services\WorkloadRow;
use App\Services\WorkloadService;

/**
 * Compose one project's status from the services that already know it.
 *
 * ## The two halves, and why they are two
 *
 * The returned structure has exactly two content keys, `recorded` and `analysis`, and nothing
 * crosses between them.
 *
 * **`recorded`** is what Planvio stores: counts, dates, minutes, amounts. Every number in it
 * came out of a column or an aggregate over columns. It can be checked, argued with, and
 * re-derived next month.
 *
 * **`analysis`** is derived judgement — the health verdict and the signals behind it. Every
 * entry carries the facts it rests on, so "at risk" is never a bare adjective.
 *
 * The separation is structural rather than stylistic. A model handed one flat object of
 * numbers and adjectives will summarise them in one voice, and a reader cannot then tell
 * "seven tasks are overdue" (a fact) from "the project is slipping" (an inference) — which is
 * exactly the blurring the system prompt's honesty rules forbid and exactly what a status
 * report is most often used to do. Keeping the halves apart in the data structure means the
 * model has to work to blur them, the UI can render them differently, and a person reading
 * the run log afterwards can see which was which.
 *
 * ## Permissions, section by section
 *
 * A report is an aggregation of things the acting user may or may not be entitled to see, so
 * it is assembled section by section rather than as one query. Money needs `budget.view`;
 * everyone's logged time needs `time.view_all`. A section the acting user cannot see is left
 * out **and named in `omitted`** — a report with a silent hole is worse than one that admits
 * it, because the model would otherwise report a budget of zero.
 *
 * ## Not a mutation
 *
 * This tool writes nothing, so it declares `isMutating(): false` and computes no idempotency
 * key. It sits at low rather than read risk because composing a report crosses several
 * permission surfaces at once, which is a decision worth a policy being able to withhold.
 */
final class GenerateProjectReportTool implements AiTool
{
    private const MAX_MILESTONES = 20;

    private const MAX_WORKLOAD_ROWS = 15;

    use MutatesThroughActions;

    public function __construct(
        private readonly ProjectProgressCalculator $progress,
        private readonly ProjectHealthCalculator $health,
        private readonly WorkloadService $workload,
        private readonly TimeReportService $time,
        private readonly BudgetService $budget,
    ) {}

    public function name(): string
    {
        return 'generate_project_report';
    }

    public function group(): string
    {
        return 'reports';
    }

    public function description(): string
    {
        return 'Build a structured status report for one project. The result separates '
            .'`recorded` (numbers Planvio actually stores) from `analysis` (derived '
            .'judgement, with the facts behind it). Keep that separation when you write it '
            .'up. Sections the acting user may not see are listed under `omitted` rather '
            .'than left out silently.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [],
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Defaults to the project this run is focused on.',
                ],
                'include_workload' => [
                    'type' => 'boolean',
                    'description' => 'Who is carrying what. Defaults to true.',
                ],
                'include_time' => [
                    'type' => 'boolean',
                    'description' => 'Logged and billable minutes. Needs permission to see everyone\'s time.',
                ],
                'include_budget' => [
                    'type' => 'boolean',
                    'description' => 'Planned against actual. Needs permission to see the budget.',
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
        return Permission::ReportsView;
    }

    /**
     * Reading and arranging, never writing. Declared honestly so the runner does not gate it
     * as a mutation or ask it for an idempotency key it has no use for.
     */
    public function isMutating(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->report($in, $ctx));
    }

    private function report(Arguments $in, AgentContext $ctx): ToolResult
    {
        $projectId = $in->nullableInt('project_id');
        $project = $projectId === null ? $ctx->project : $this->resolveProject($projectId, $ctx);

        if (! $project instanceof Project) {
            return $projectId === null ? $this->projectRequired() : $this->notFound(__('project'), $projectId);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::ReportsView, $project)) {
            return $this->denied($ctx, __('see reports for :project', ['project' => $project->name]));
        }

        /** @var list<string> $omitted */
        $omitted = [];

        $progress = $this->progress->forProject($project);
        $workloadRows = $in->bool('include_workload', true)
            ? $this->workload->forProject($project, null, $ctx->now())
            : [];

        $recorded = [
            'identity' => [
                'project_id' => (int) $project->getKey(),
                'name' => $project->name,
                'key' => $project->key,
                'type' => $project->type->value,
                'priority' => $project->priority->value,
                'is_archived' => (bool) $project->is_archived,
            ],
            'dates' => [
                'start_date' => $project->start_date?->format('Y-m-d'),
                'target_date' => $project->target_date?->format('Y-m-d'),
                'completed_at' => $project->completed_at?->format('Y-m-d'),
                'today' => $ctx->today()->toDateString(),
                'timezone' => $ctx->resolvedTimezone(),
            ],
            'tasks' => [
                'total' => $progress->total,
                'completed' => $progress->completed,
                'open' => $progress->open(),
                'overdue' => $this->overdue($workloadRows),
                'unassigned_open' => $this->unassignedOpen($workloadRows),
                'percentage_complete' => $progress->percentage,
            ],
            'milestones' => $this->milestones($project, $ctx),
            'stored_health' => $project->health->value,
            'stored_health_is_manual' => (bool) $project->health_set_manually,
        ];

        if ($workloadRows !== []) {
            $recorded['workload'] = array_map(
                static fn (WorkloadRow $row): array => $row->toArray(),
                array_slice($workloadRows, 0, self::MAX_WORKLOAD_ROWS),
            );
        }

        if ($in->bool('include_time', true)) {
            if ($ctx->can(Permission::TimeViewAll, $project)) {
                $range = DateRange::everything();

                $recorded['time'] = [
                    'logged_minutes' => $this->time->totalMinutes($project, $range),
                    'billable_minutes' => $this->time->billableMinutes($project, $range),
                ];
            } else {
                $omitted[] = 'time';
            }
        }

        if ($in->bool('include_budget', true)) {
            if ($ctx->can(Permission::BudgetView, $project)) {
                $recorded['budget'] = $this->budget->forProject($project)->toArray();
            } else {
                $omitted[] = 'budget';
            }
        }

        $assessment = $this->health->calculate($project, $ctx->now());

        $analysis = [
            'health_verdict' => $assessment->health->value,
            'health_computed' => $assessment->computed->value,
            'health_was_set_by_hand' => $assessment->manual,
            'stored_value_overrides_calculation' => $assessment->overridesComputed(),
            'reasons' => array_slice($assessment->reasons, 0, 10),
            'basis' => __('Derived by Planvio\'s health calculator from the counts and dates under "recorded", as at :date.', [
                'date' => $ctx->today()->toDateString(),
            ]),
        ];

        return ToolResult::ok(
            $this->summary($project, $recorded, $assessment->health->value, $omitted),
            [
                'generated_at' => $ctx->now()->toIso8601String(),
                'separation' => __('Everything under "recorded" is a value Planvio stores. Everything under "analysis" is derived judgement. Do not present the second as the first.'),
                'recorded' => $recorded,
                'analysis' => $analysis,
                'omitted' => $omitted,
                'omitted_reason' => $omitted === []
                    ? null
                    : __('Those sections are outside :user\'s permissions, so they are absent rather than zero.', [
                        'user' => $ctx->user->name,
                    ]),
            ],
            $project,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function milestones(Project $project, AgentContext $ctx): array
    {
        if ($ctx->cannot(Permission::MilestoneView, $project)) {
            return [];
        }

        $milestones = $ctx->bindWorkspace(static fn (): array => Milestone::query()
            ->forWorkspace($ctx->workspaceId())
            ->forProject($project)
            ->ordered()
            ->limit(self::MAX_MILESTONES)
            ->get()
            ->all());

        return array_map(static fn (Milestone $milestone): array => [
            'id' => (int) $milestone->getKey(),
            'name' => self::clip($milestone->name, 120),
            'status' => $milestone->status->value,
            'due_date' => $milestone->due_date?->format('Y-m-d'),
            'completed_at' => $milestone->completed_at?->format('Y-m-d'),
            'progress' => (int) $milestone->progress,
        ], $milestones);
    }

    /**
     * @param list<WorkloadRow> $rows
     */
    private function overdue(array $rows): int
    {
        return array_sum(array_map(static fn (WorkloadRow $row): int => $row->overdue, $rows));
    }

    /**
     * @param list<WorkloadRow> $rows
     */
    private function unassignedOpen(array $rows): int
    {
        foreach ($rows as $row) {
            if ($row->isUnassigned()) {
                return $row->open;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $recorded
     * @param list<string> $omitted
     */
    private function summary(Project $project, array $recorded, string $verdict, array $omitted): string
    {
        $tasks = is_array($recorded['tasks'] ?? null) ? $recorded['tasks'] : [];

        $summary = __('Report for :project: :completed of :total tasks complete (:percent%), :overdue overdue. Recorded health: :stored. Calculated verdict: :verdict — that verdict is analysis, not a stored figure.', [
            'project' => $project->name,
            'completed' => (int) ($tasks['completed'] ?? 0),
            'total' => (int) ($tasks['total'] ?? 0),
            'percent' => (int) ($tasks['percentage_complete'] ?? 0),
            'overdue' => (int) ($tasks['overdue'] ?? 0),
            'stored' => (string) ($recorded['stored_health'] ?? ''),
            'verdict' => $verdict,
        ]);

        if ($omitted !== []) {
            $summary .= ' '.__('Left out because the acting user may not see them: :sections.', [
                'sections' => implode(', ', $omitted),
            ]);
        }

        return $summary;
    }
}
