<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Services\BudgetService;
use App\Services\DateRange;
use App\Services\DateResolver;

/**
 * Planned against actual against variance for one project.
 *
 * Two gates, in order: `project.view` decides whether the project exists as far as this
 * caller is concerned, and `budget.view` decides whether its money does. The capability
 * matrix gives `budget.view` to owners, admins and project managers only, so an ordinary
 * member reading a project through `get_project` sees a note where the budget would be and
 * a refusal here — never a figure.
 *
 * Amounts come back as decimal strings in the project's own currency, exactly as
 * {@see BudgetService} computed them in integer minor units. Costs booked in another
 * currency are reported under `unconverted` rather than added in: Planvio ships no exchange
 * rates, and a total that silently sums across currencies is worse than one that admits it
 * cannot. Logged effort travels alongside as minutes, never as money.
 */
final class GetBudgetSummaryTool extends ReadTool
{
    public function __construct(
        private readonly BudgetService $budgets = new BudgetService,
        private readonly DateResolver $dates = new DateResolver,
    ) {}

    public function name(): string
    {
        return 'get_budget_summary';
    }

    public function description(): string
    {
        return 'Read one project\'s budget: planned amount, actual spend, variance and '
            .'utilisation in the project currency, plus logged and billable minutes. '
            .'Costs booked in other currencies are listed separately, never converted.';
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
                'from' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Only count expenses and time from this day onwards. Requires "to". Omit both for the project to date.',
                ],
                'to' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Last day counted, inclusive. Requires "from".',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::BudgetView;
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

        if ($ctx->cannot(Permission::BudgetView, $project)) {
            return $this->denied('budget');
        }

        [$range, $failure] = $this->range($args, $ctx);

        if ($failure !== null) {
            return $failure;
        }

        $budget = $this->budgets->forProject($project, $range);

        $data = $budget->toArray();
        $data['project'] = [
            'id' => (int) $project->getKey(),
            'key' => (string) $project->key,
            'name' => (string) $project->name,
        ];
        $data['range'] = $range?->toArray();
        $data['is_over_budget'] = $budget->isOverBudget();
        $data['note'] = __('ai.tools.notes.labour_not_costed');

        if ($budget->hasUnconvertedCosts()) {
            $data['unconverted_note'] = __('ai.tools.notes.unconverted_costs');
        }

        $summary = $budget->hasBudget()
            ? __('ai.tools.summary.budget', [
                'project' => $project->key.' '.$project->name,
                'actual' => $budget->actual(),
                'planned' => (string) $budget->planned(),
                'currency' => $budget->currency,
                'utilisation' => (string) ($budget->utilisation() ?? 0),
            ])
            : __('ai.tools.summary.budget_unset', [
                'project' => $project->key.' '.$project->name,
                'actual' => $budget->actual(),
                'currency' => $budget->currency,
            ]);

        return ToolResult::ok($summary, $data, $project);
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: DateRange|null, 1: ToolResult|null}
     */
    private function range(array $args, AgentContext $ctx): array
    {
        $from = is_string($args['from'] ?? null) ? $args['from'] : null;
        $to = is_string($args['to'] ?? null) ? $args['to'] : null;

        if ($from === null && $to === null) {
            return [null, null];
        }

        if ($from === null || $to === null) {
            return [null, ToolResult::failed(
                __('ai.tools.contradictory_filters', ['first' => 'from', 'second' => 'to']),
                'invalid_arguments',
            )];
        }

        $start = $this->dates->forWorkspace($ctx->workspace, $from, $ctx->now());
        $end = $this->dates->forWorkspace($ctx->workspace, $to, $ctx->now());

        if ($start === null) {
            return [null, ToolResult::failed(
                __('ai.tools.unreadable_date', ['field' => 'from', 'value' => self::clip($from, 64)]),
                'invalid_arguments',
            )];
        }

        if ($end === null) {
            return [null, ToolResult::failed(
                __('ai.tools.unreadable_date', ['field' => 'to', 'value' => self::clip($to, 64)]),
                'invalid_arguments',
            )];
        }

        if ($end->lessThan($start)) {
            return [null, ToolResult::failed(
                __('ai.tools.contradictory_filters', ['first' => 'from', 'second' => 'to']),
                'invalid_arguments',
            )];
        }

        return [new DateRange($start, $end), null];
    }
}
