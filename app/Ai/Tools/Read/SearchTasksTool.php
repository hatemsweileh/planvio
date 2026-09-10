<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Enums\Priority;
use App\Enums\StatusCategory;
use App\Models\Task;
use App\Services\DateResolver;
use App\Services\SearchResult;
use App\Services\SearchService;
use App\Services\SearchType;

/**
 * Find tasks the acting user may see, by text and by the filters people actually ask for.
 *
 * Visibility is the project's: a task is reachable only through
 * {@see ReadTool::visibleProjectIds()}, so a guest sees the tasks of their own projects and
 * nothing else, and a task id the model produced from somewhere it should not know simply
 * fails to match.
 *
 * Relative dates ("friday", "end of month") are resolved through {@see DateResolver} in the
 * workspace timezone, and the resolved day is echoed back in the result so the model reports
 * the date it actually filtered on rather than the phrase it typed.
 */
final class SearchTasksTool extends ReadTool
{
    public function __construct(
        private readonly SearchService $search = new SearchService,
        private readonly DateResolver $dates = new DateResolver,
    ) {}

    public function name(): string
    {
        return 'search_tasks';
    }

    public function description(): string
    {
        return 'Find tasks in this workspace by free text and/or by project, status category, '
            .'assignee, priority, milestone, overdue state and due-date window. Returns only '
            .'tasks in projects the acting user may see, and reports how many matches were '
            .'not returned.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 128,
                    'description' => 'Free text matched against task title and description. Also accepts a task key such as WEB-42.',
                ],
                'project' => [
                    'type' => ['integer', 'string'],
                    'description' => 'Restrict to one project, by id or by project key such as WEB.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => self::statusCategories(),
                    'description' => 'Category of the task status: backlog, todo, in_progress, review, blocked, done or cancelled.',
                ],
                'assignee' => [
                    'type' => ['integer', 'string'],
                    'description' => 'Assignee user id, or the literal "me" for the acting user.',
                ],
                'unassigned' => [
                    'type' => 'boolean',
                    'description' => 'Only tasks with no assignee. Cannot be combined with assignee.',
                ],
                'priority' => [
                    'type' => ['string', 'array'],
                    'items' => ['type' => 'string', 'enum' => self::priorities()],
                    'enum' => self::priorities(),
                    'maxItems' => 5,
                    'description' => 'One priority, or a list of them.',
                ],
                'overdue' => [
                    'type' => 'boolean',
                    'description' => 'Only open tasks whose due date has passed in the workspace timezone.',
                ],
                'due_before' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Only tasks due on or before this day. Accepts YYYY-MM-DD or a phrase such as "friday" or "end of month".',
                ],
                'due_after' => [
                    'type' => 'string',
                    'maxLength' => 64,
                    'description' => 'Only tasks due on or after this day. Same formats as due_before.',
                ],
                'milestone' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Only tasks attached to this milestone id.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum tasks to return.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::TaskView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        if (! $ctx->allows('viewAny', [Task::class])) {
            return $this->denied('tasks');
        }

        $assignee = $args['assignee'] ?? null;
        $unassigned = ($args['unassigned'] ?? false) === true;

        if ($assignee !== null && $unassigned) {
            return ToolResult::failed(
                __('ai.tools.contradictory_filters', ['first' => 'assignee', 'second' => 'unassigned']),
                'invalid_arguments',
            );
        }

        $query = Task::query()
            ->forWorkspace($ctx->workspace)
            ->whereIn('tasks.project_id', $this->visibleProjectIds($ctx));

        $applied = [];

        if (($reference = $args['project'] ?? null) !== null) {
            $project = $this->resolveProject($ctx, is_int($reference) ? $reference : (string) $reference);

            if ($project === null) {
                return $this->notFound('project', is_int($reference) ? $reference : (string) $reference);
            }

            $ctx->assertInWorkspace($project);

            if ($ctx->cannot(Permission::ProjectView, $project)) {
                return $this->denied('project');
            }

            $query->forProject($project);
            $applied['project'] = $project->key.' '.$project->name;
        }

        if (is_string($args['status'] ?? null)) {
            $category = StatusCategory::tryFrom($args['status']);

            if ($category !== null) {
                $query->inStatusCategory($category);
                $applied['status_category'] = $category->value;
            }
        }

        if ($assignee !== null) {
            $userId = $this->resolveAssignee($assignee, $ctx);

            if ($userId === null) {
                return ToolResult::failed(
                    __('ai.tools.not_found', ['subject' => 'assignee', 'reference' => self::clip((string) $assignee, 64)]),
                    'not_found',
                );
            }

            $query->assignedTo($userId);
            $applied['assignee_id'] = $userId;
        }

        if ($unassigned) {
            $query->unassigned();
            $applied['unassigned'] = true;
        }

        $priorities = $this->priorityFilter($args['priority'] ?? null);

        if ($priorities !== []) {
            $query->withPriority($priorities);
            $applied['priority'] = array_map(static fn (Priority $case): string => $case->value, $priorities);
        }

        $hasDateConstraint = false;

        if (($args['overdue'] ?? false) === true) {
            $query->overdue($ctx->today());
            $applied['overdue_as_of'] = $ctx->today()->toDateString();
            $hasDateConstraint = true;
        }

        foreach (['due_after' => '>=', 'due_before' => '<='] as $field => $operator) {
            $phrase = $args[$field] ?? null;

            if (! is_string($phrase) || $phrase === '') {
                continue;
            }

            $day = $this->dates->forWorkspace($ctx->workspace, $phrase, $ctx->now());

            if ($day === null) {
                return ToolResult::failed(
                    __('ai.tools.unreadable_date', ['field' => $field, 'value' => self::clip($phrase, 64)]),
                    'invalid_arguments',
                );
            }

            // The upper bound is exclusive on the next day: a `date` column reads back bare
            // on MySQL and as the cast's "Y-m-d H:i:s" on SQLite, and an inclusive `<=`
            // against a bare date would drop everything due on that last day under SQLite.
            $query->whereNotNull('tasks.due_date');

            if ($operator === '>=') {
                $query->where('tasks.due_date', '>=', $day->toDateString());
            } else {
                $query->where('tasks.due_date', '<', $day->addDay()->toDateString());
            }

            $applied[$field] = $day->toDateString();
            $hasDateConstraint = true;
        }

        if (is_int($args['milestone'] ?? null)) {
            $milestone = $this->resolveMilestone($ctx, $args['milestone']);

            if ($milestone === null) {
                return $this->notFound('milestone', $args['milestone']);
            }

            $ctx->assertInWorkspace($milestone);

            $query->where('tasks.milestone_id', $milestone->getKey());
            $applied['milestone'] = (string) $milestone->name;
        }

        $capped = false;
        $term = is_string($args['query'] ?? null) ? $args['query'] : null;

        if ($term !== null) {
            $matches = $this->search->search(
                $term,
                $ctx->user,
                $ctx->workspace,
                [SearchType::Task],
                self::TEXT_SEARCH_CANDIDATES,
            );

            $ids = array_map(
                static fn (SearchResult $result): int => $result->id,
                $matches->for(SearchType::Task),
            );

            $capped = $matches->isTruncated(SearchType::Task);

            if ($ids === []) {
                return ToolResult::ok(
                    __('ai.tools.summary.tasks', ['returned' => 0, 'total' => 0]),
                    ['returned' => 0, 'total' => 0, 'omitted' => 0, 'filters' => $applied, 'tasks' => []]
                        + ($capped ? ['text_search_capped' => true] : []),
                );
            }

            $query->whereKey($ids);
        }

        $total = (clone $query)->count();
        $limit = self::pageSize($args['limit'] ?? null, 'search', 20);

        if ($hasDateConstraint) {
            $query->orderBy('tasks.due_date')->orderBy('tasks.id');
        } else {
            $query->orderByDesc('tasks.updated_at')->orderByDesc('tasks.id');
        }

        $tasks = $query
            ->with([
                'project:id,key,name',
                'status:id,name,category,is_completed',
                'assignee:id,name',
                'milestone:id,name',
            ])
            ->limit($limit)
            ->get();

        $today = $ctx->today()->toDateString();
        $rows = [];

        foreach ($tasks as $task) {
            $due = self::day($task->due_date);

            $rows[] = [
                'id' => (int) $task->getKey(),
                'key' => (string) $task->key,
                'title' => (string) $task->title,
                'project' => $task->project?->key,
                'status' => $task->status?->name,
                'status_category' => $task->status?->category?->value,
                'is_completed' => $task->completed_at !== null,
                'priority' => $task->priority?->value,
                'assignee_id' => $task->assignee_id === null ? null : (int) $task->assignee_id,
                'assignee' => $task->assignee?->name,
                'due_date' => $due,
                'is_overdue' => $due !== null && $task->completed_at === null && $due < $today,
                'milestone' => $task->milestone?->name,
                'estimate_minutes' => $task->estimate_minutes === null ? null : (int) $task->estimate_minutes,
                'progress' => (int) $task->progress,
            ];
        }

        $data = $this->page($rows, $total, 'tasks');
        $data['filters'] = $applied;

        if ($capped) {
            $data['text_search_capped'] = true;
            $data['note'] = __('ai.tools.text_search_capped', ['limit' => self::TEXT_SEARCH_CANDIDATES]);
        }

        $data = $this->fit($data, 'tasks');

        return ToolResult::ok(
            __('ai.tools.summary.tasks', [
                'returned' => $data['returned'],
                'total' => $data['total'],
            ]),
            $data,
        );
    }

    /**
     * "me" resolves to the acting user. Any other value must be a positive id, and it is
     * only ever used as a bound `where` value — never to widen what the query may reach.
     */
    private function resolveAssignee(mixed $value, AgentContext $ctx): ?int
    {
        if (is_string($value) && mb_strtolower(trim($value)) === 'me') {
            return $ctx->userId();
        }

        return self::asId($value);
    }

    /**
     * @return list<Priority>
     */
    private function priorityFilter(mixed $value): array
    {
        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $priorities = [];

        foreach ($values as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $priority = Priority::tryFrom($entry);

            if ($priority !== null) {
                $priorities[] = $priority;
            }
        }

        return array_values(array_unique($priorities, SORT_REGULAR));
    }

    /**
     * @return list<string>
     */
    private static function statusCategories(): array
    {
        return array_map(static fn (StatusCategory $case): string => $case->value, StatusCategory::cases());
    }

    /**
     * @return list<string>
     */
    private static function priorities(): array
    {
        return array_map(static fn (Priority $case): string => $case->value, Priority::cases());
    }
}
