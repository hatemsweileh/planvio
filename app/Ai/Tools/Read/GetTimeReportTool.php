<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Services\DateRange;
use App\Services\DateResolver;
use App\Services\TimeReportRow;
use App\Services\TimeReportService;

/**
 * Logged minutes over a date range, grouped by project, person, task or day.
 *
 * Two permissions apply and they do different jobs. `reports.view` decides whether the
 * report may be run at all. `time.view_all` decides whose entries it may cover: without it
 * the report is silently — no, *explicitly* — narrowed to the acting user's own entries, and
 * `limited_to_own_entries` says so in the result. Grouping by person is refused outright in
 * that case rather than quietly returning a one-row report, because a person-by-person
 * breakdown that contains one person is a misleading answer to the question that was asked.
 *
 * Minutes are never converted into money. Planvio stores no rates anywhere, so a costed
 * timesheet would be a number invented to look authoritative.
 */
final class GetTimeReportTool extends ReadTool
{
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 366;

    public function __construct(
        private readonly TimeReportService $reports = new TimeReportService,
        private readonly DateResolver $dates = new DateResolver,
    ) {}

    public function name(): string
    {
        return 'get_time_report';
    }

    public function description(): string
    {
        return 'Report logged time over a date range for one project or the whole workspace, '
            .'grouped by project, person, task or day. Returns minutes and billable minutes, '
            .'never money. Without permission to see other people\'s time, the report covers '
            .'only the acting user and says so.';
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
                'group_by' => [
                    'type' => 'string',
                    'enum' => ['project', 'user', 'task', 'day'],
                    'default' => 'project',
                    'description' => 'How to group the totals.',
                ],
                'from' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'First day of the range. YYYY-MM-DD or a phrase such as "start of month". Defaults to 30 days ago.',
                ],
                'to' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Last day of the range, inclusive. Defaults to today.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum rows to return, most minutes first (chronological when grouped by day).',
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

        $seesEveryone = $project instanceof Project
            ? $ctx->can(Permission::TimeViewAll, $project)
            : $ctx->allows('viewAll', [TimeEntry::class]);

        $groupBy = is_string($args['group_by'] ?? null) ? $args['group_by'] : 'project';

        if ($groupBy === 'user' && ! $seesEveryone) {
            return $this->denied('time_by_user');
        }

        [$range, $failure] = $this->range($args, $ctx);

        if ($failure !== null) {
            return $failure;
        }

        $user = $seesEveryone ? null : $ctx->user;

        $rows = match ($groupBy) {
            'user' => $this->reports->byUser($subject, $range),
            'task' => $this->reports->byTask($subject, $range, $user),
            'day' => $this->reports->byDay($subject, $range, $user),
            default => $this->reports->byProject($subject, $range, $user),
        };

        $limit = self::pageSize($args['limit'] ?? null, 'list', 50);
        $totalMinutes = TimeReportRow::totalMinutes($rows);

        $data = $this->page(
            array_map(static fn (TimeReportRow $row): array => $row->toArray(), array_slice($rows, 0, $limit)),
            count($rows),
            'rows',
        );

        $data['scope'] = $project === null
            ? ['type' => 'workspace', 'name' => (string) $ctx->workspace->name]
            : ['type' => 'project', 'id' => (int) $project->getKey(), 'key' => (string) $project->key, 'name' => (string) $project->name];
        $data['range'] = $range->toArray();
        $data['group_by'] = $groupBy;
        $data['total_minutes'] = $totalMinutes;
        $data['total_hours'] = round($totalMinutes / 60, 2);
        $data['limited_to_own_entries'] = ! $seesEveryone;
        $data['note'] = __('ai.tools.notes.labour_not_costed');

        if (! $seesEveryone) {
            $data['scope_note'] = __('ai.tools.notes.own_time_only');
        }

        $data = $this->fit($data, 'rows');

        return ToolResult::ok(
            __('ai.tools.summary.time', [
                'scope' => $project === null
                    ? __('ai.tools.labels.workspace_scope')
                    : $project->key.' '.$project->name,
                'from' => $range->fromDate(),
                'to' => $range->toDate(),
                'group' => $groupBy,
                'hours' => (string) round($totalMinutes / 60, 2),
                'rows' => $data['returned'],
            ]),
            $data,
            $project,
        );
    }

    /**
     * The range, defaulting to a rolling month ending today in the workspace timezone and
     * clamped to a year so a single call cannot scan the whole history of a workspace.
     *
     * @param array<string, mixed> $args
     * @return array{0: DateRange, 1: ToolResult|null}
     */
    private function range(array $args, AgentContext $ctx): array
    {
        $timezone = $ctx->resolvedTimezone();
        $default = DateRange::lastDays(self::DEFAULT_DAYS, $timezone, $ctx->now());

        $from = is_string($args['from'] ?? null) ? $args['from'] : null;
        $to = is_string($args['to'] ?? null) ? $args['to'] : null;

        $start = $from === null ? $default->from : $this->dates->forWorkspace($ctx->workspace, $from, $ctx->now());
        $end = $to === null ? $default->to : $this->dates->forWorkspace($ctx->workspace, $to, $ctx->now());

        if ($start === null) {
            return [$default, ToolResult::failed(
                __('ai.tools.unreadable_date', ['field' => 'from', 'value' => self::clip((string) $from, 64)]),
                'invalid_arguments',
            )];
        }

        if ($end === null) {
            return [$default, ToolResult::failed(
                __('ai.tools.unreadable_date', ['field' => 'to', 'value' => self::clip((string) $to, 64)]),
                'invalid_arguments',
            )];
        }

        if ($end->lessThan($start)) {
            return [$default, ToolResult::failed(
                __('ai.tools.contradictory_filters', ['first' => 'from', 'second' => 'to']),
                'invalid_arguments',
            )];
        }

        if ((int) $start->diffInDays($end) + 1 > self::MAX_DAYS) {
            $start = $end->subDays(self::MAX_DAYS - 1);
        }

        return [new DateRange($start, $end), null];
    }
}
