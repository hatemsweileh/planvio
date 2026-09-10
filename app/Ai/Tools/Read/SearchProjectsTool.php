<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Enums\ProjectHealth;
use App\Enums\StatusCategory;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Services\SearchResult;
use App\Services\SearchService;
use App\Services\SearchType;
use Illuminate\Database\Eloquent\Builder;

/**
 * Find projects the acting user may see.
 *
 * Text matching is delegated to {@see SearchService}, which already resolves the caller's
 * role and applies `Project::visibleTo()` — so a guest searching here reaches only the
 * projects they were added to, exactly as they would through the product's own search box.
 * The structured filters are then applied to a query that repeats that visibility rule,
 * which is why the two halves cannot disagree.
 *
 * The service caps a text search at its own ceiling, so a very broad term can hide matches.
 * That is reported rather than smoothed over: a model that believes it has seen every
 * project will confidently tell someone a project does not exist.
 */
final class SearchProjectsTool extends ReadTool
{
    public function __construct(
        private readonly SearchService $search = new SearchService,
    ) {}

    public function name(): string
    {
        return 'search_projects';
    }

    public function description(): string
    {
        return 'Find projects in this workspace by free text and/or by status category, '
            .'health and archived state. Returns only projects the acting user may see, '
            .'newest activity first, with a count of how many matches were not returned.';
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
                    'description' => 'Free text matched against project name, description and key. Omit to list by filter alone.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => self::statusCategories(),
                    'description' => 'Category of the project status, e.g. in_progress for active work.',
                ],
                'health' => [
                    'type' => 'string',
                    'enum' => self::healthValues(),
                    'description' => 'The health value recorded on the project.',
                ],
                'archived' => [
                    'type' => 'string',
                    'enum' => ['exclude', 'include', 'only'],
                    'default' => 'exclude',
                    'description' => 'Whether archived projects are excluded (default), included alongside active ones, or the only ones returned.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum projects to return.',
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
        // The class-level form of `project.view`: there is no single subject to judge a
        // search against, and every row returned is filtered by visibleTo() below.
        if (! $ctx->allows('viewAny', [Project::class])) {
            return $this->denied('projects');
        }

        $archived = is_string($args['archived'] ?? null) ? $args['archived'] : 'exclude';
        $limit = self::pageSize($args['limit'] ?? null, 'search', 20);
        $query = $this->visibleProjects($ctx);

        match ($archived) {
            'only' => $query->archived(),
            'include' => null,
            default => $query->active(),
        };

        $status = is_string($args['status'] ?? null) ? StatusCategory::tryFrom($args['status']) : null;

        if ($status !== null) {
            $query->whereIn(
                'projects.status_id',
                ProjectStatus::query()
                    ->forWorkspace($ctx->workspace)
                    ->where('project_statuses.category', $status->value)
                    ->select('project_statuses.id')
                    ->toBase(),
            );
        }

        $health = is_string($args['health'] ?? null) ? ProjectHealth::tryFrom($args['health']) : null;

        if ($health !== null) {
            $query->where('projects.health', $health->value);
        }

        $term = is_string($args['query'] ?? null) ? $args['query'] : null;
        $capped = false;

        if ($term !== null) {
            $matches = $this->search->search(
                $term,
                $ctx->user,
                $ctx->workspace,
                [SearchType::Project],
                self::TEXT_SEARCH_CANDIDATES,
                includeArchived: $archived !== 'exclude',
            );

            $ids = array_map(
                static fn (SearchResult $result): int => $result->id,
                $matches->for(SearchType::Project),
            );

            $capped = $matches->isTruncated(SearchType::Project);

            if ($ids === []) {
                return ToolResult::ok(
                    __('ai.tools.summary.projects', ['returned' => 0, 'total' => 0]),
                    ['returned' => 0, 'total' => 0, 'omitted' => 0, 'projects' => []]
                        + ($capped ? ['text_search_capped' => true] : []),
                );
            }

            $query->whereKey($ids);
        }

        $total = (clone $query)->count();

        $projects = $query
            ->with(['status:id,name,category', 'owner:id,name', 'manager:id,name'])
            ->withCount([
                'tasks as open_task_count' => static fn (Builder $tasks): Builder => $tasks->whereNull('tasks.completed_at'),
            ])
            ->orderByDesc('projects.updated_at')
            ->orderByDesc('projects.id')
            ->limit($limit)
            ->get();

        $rows = [];

        foreach ($projects as $project) {
            $rows[] = [
                'id' => (int) $project->getKey(),
                'key' => (string) $project->key,
                'name' => (string) $project->name,
                'type' => $project->type?->value,
                'status' => $project->status?->name,
                'status_category' => $project->status?->category?->value,
                'health' => $project->health?->value,
                'priority' => $project->priority?->value,
                'progress' => (int) $project->progress,
                'open_tasks' => (int) ($project->getAttribute('open_task_count') ?? 0),
                'target_date' => self::day($project->target_date),
                'owner' => $project->owner?->name,
                'manager' => $project->manager?->name,
                'is_archived' => (bool) $project->is_archived,
                'excerpt' => self::excerpt($project->description, 160),
            ];
        }

        $data = $this->page($rows, $total, 'projects');

        if ($capped) {
            $data['text_search_capped'] = true;
            $data['note'] = __('ai.tools.text_search_capped', ['limit' => self::TEXT_SEARCH_CANDIDATES]);
        }

        $data = $this->fit($data, 'projects');

        return ToolResult::ok(
            __('ai.tools.summary.projects', [
                'returned' => $data['returned'],
                'total' => $data['total'],
            ]),
            $data,
        );
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
    private static function healthValues(): array
    {
        return array_map(static fn (ProjectHealth $case): string => $case->value, ProjectHealth::cases());
    }
}
