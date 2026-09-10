<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Services\DateRange;
use App\Services\DateResolver;
use App\Services\WorkloadRow;
use App\Services\WorkloadService;

/**
 * Who is carrying what, for one project or for the whole workspace.
 *
 * The gate is `reports.view`, which the capability matrix withholds from guests entirely —
 * which is the point. {@see WorkloadService::forWorkspace()} aggregates every task in the
 * workspace, not only the ones the caller could open individually, so a role that may not
 * see the whole workspace must not reach the workspace form of this report. Project scope is
 * judged against the project itself, so a project manager gets their own project and nothing
 * more.
 *
 * A date window narrows on `due_date`, because that is the question capacity planning asks:
 * what is *due* in this window. Undated tasks are therefore absent from a windowed report
 * and present in an unwindowed one, and the result says which it is rather than leaving the
 * model to assume the totals cover everything.
 */
final class GetTeamWorkloadTool extends ReadTool
{
    public function __construct(
        private readonly WorkloadService $workload = new WorkloadService,
        private readonly DateResolver $dates = new DateResolver,
    ) {}

    public function name(): string
    {
        return 'get_team_workload';
    }

    public function description(): string
    {
        return 'Report open, completed and overdue task counts per person, for one project '
            .'or for the whole workspace, optionally narrowed to tasks due within a window. '
            .'Includes an unassigned bucket. People holding nothing are listed too.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'project' => [
                    'type' => ['integer', 'string'],
                    'description' => 'Restrict to one project, by id or key. Omit for the whole workspace.',
                ],
                'due_from' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Start of the due-date window. YYYY-MM-DD or a phrase such as "today". Requires due_to.',
                ],
                'due_to' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'End of the due-date window, inclusive. Requires due_from.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum rows to return, busiest first.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::ReportsView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $reference = $args['project'] ?? null;
        $project = null;

        if ($reference !== null) {
            $reference = is_int($reference) ? $reference : (string) $reference;
            $project = $this->resolveProject($ctx, $reference);

            if ($project === null) {
                return $this->notFound('project', $reference);
            }

            $ctx->assertInWorkspace($project);
        }

        $subject = $project ?? $ctx->workspace;

        if ($ctx->cannot(Permission::ReportsView, $subject)) {
            return $this->denied('reports');
        }

        [$range, $failure] = $this->window($args, $ctx);

        if ($failure !== null) {
            return $failure;
        }

        $rows = $project === null
            ? $this->workload->forWorkspace($ctx->workspace, $range, $ctx->today())
            : $this->workload->forProject($project, $range, $ctx->today());

        $limit = self::pageSize($args['limit'] ?? null, 'list', 50);

        $open = 0;
        $overdue = 0;

        foreach ($rows as $row) {
            $open += $row->open;
            $overdue += $row->overdue;
        }

        $shown = array_slice($rows, 0, $limit);

        $data = $this->page(
            array_map(
                static fn (WorkloadRow $row): array => $row->toArray() + [
                    'is_unassigned' => $row->isUnassigned(),
                    'overdue_share' => $row->overdueShare(),
                    'estimate_hours' => $row->estimateHours(),
                ],
                $shown,
            ),
            count($rows),
            'rows',
        );

        $data['scope'] = $project === null
            ? ['type' => 'workspace', 'name' => (string) $ctx->workspace->name]
            : ['type' => 'project', 'id' => (int) $project->getKey(), 'key' => (string) $project->key, 'name' => (string) $project->name];
        $data['as_of'] = $ctx->today()->toDateString();
        $data['due_window'] = $range?->toArray();
        $data['totals'] = ['open' => $open, 'overdue' => $overdue];

        if ($range !== null) {
            $data['window_note'] = __('ai.tools.notes.due_window');
        }

        $data = $this->fit($data, 'rows');

        return ToolResult::ok(
            __('ai.tools.summary.workload', [
                'scope' => $project === null
                    ? __('ai.tools.labels.workspace_scope')
                    : $project->key.' '.$project->name,
                'returned' => $data['returned'],
                'total' => $data['total'],
                'open' => $open,
                'overdue' => $overdue,
            ]),
            $data,
            $project,
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: DateRange|null, 1: ToolResult|null}
     */
    private function window(array $args, AgentContext $ctx): array
    {
        $from = is_string($args['due_from'] ?? null) ? $args['due_from'] : null;
        $to = is_string($args['due_to'] ?? null) ? $args['due_to'] : null;

        if ($from === null && $to === null) {
            return [null, null];
        }

        if ($from === null || $to === null) {
            return [null, ToolResult::failed(
                __('ai.tools.contradictory_filters', ['first' => 'due_from', 'second' => 'due_to']),
                'invalid_arguments',
            )];
        }

        $start = $this->dates->forWorkspace($ctx->workspace, $from, $ctx->now());
        $end = $this->dates->forWorkspace($ctx->workspace, $to, $ctx->now());

        if ($start === null) {
            return [null, ToolResult::failed(
                __('ai.tools.unreadable_date', ['field' => 'due_from', 'value' => self::clip($from, 64)]),
                'invalid_arguments',
            )];
        }

        if ($end === null) {
            return [null, ToolResult::failed(
                __('ai.tools.unreadable_date', ['field' => 'due_to', 'value' => self::clip($to, 64)]),
                'invalid_arguments',
            )];
        }

        if ($end->lessThan($start)) {
            return [null, ToolResult::failed(
                __('ai.tools.contradictory_filters', ['first' => 'due_from', 'second' => 'due_to']),
                'invalid_arguments',
            )];
        }

        return [new DateRange($start, $end), null];
    }
}
