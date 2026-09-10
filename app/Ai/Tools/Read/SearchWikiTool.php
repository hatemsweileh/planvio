<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Models\WikiPage;
use App\Services\SearchService;
use App\Services\SearchType;

/**
 * Search the wiki pages the acting user may read.
 *
 * Visibility is delegated whole to {@see WikiPage::scopeVisibleTo()} through
 * {@see SearchService}, which encodes all three rules in one place: workspace-level pages
 * are open to every member, project pages need project membership or a workspace role above
 * guest, and private pages stay with their author. Reimplementing any of that here would
 * create a second definition of "may read", and the two would eventually disagree.
 *
 * The optional project filter is applied to the results rather than the query, because the
 * service takes no project argument. That means a broad term can fill the candidate window
 * before the filter narrows it — reported as `text_search_capped`, never hidden.
 */
final class SearchWikiTool extends ReadTool
{
    public function __construct(
        private readonly SearchService $search = new SearchService,
    ) {}

    public function name(): string
    {
        return 'search_wiki';
    }

    public function description(): string
    {
        return 'Search wiki page titles, excerpts and content in this workspace. Returns only '
            .'pages the acting user may read, with a short excerpt of each. Optionally '
            .'restricted to one project.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 128,
                    'description' => 'Free text matched against page title, excerpt and content.',
                ],
                'project' => [
                    'type' => ['integer', 'string'],
                    'description' => 'Restrict to pages belonging to this project, by id or key.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum pages to return.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::WikiView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $project = null;

        if (($reference = $args['project'] ?? null) !== null) {
            $reference = is_int($reference) ? $reference : (string) $reference;
            $project = $this->resolveProject($ctx, $reference);

            if ($project === null) {
                return $this->notFound('project', $reference);
            }

            $ctx->assertInWorkspace($project);

            if ($ctx->cannot(Permission::ProjectView, $project)) {
                return $this->denied('project');
            }
        }

        if (! $ctx->allows('viewAny', [WikiPage::class, $project])) {
            return $this->denied('wiki');
        }

        $term = (string) $args['query'];
        $limit = self::pageSize($args['limit'] ?? null, 'search', 20);

        $matches = $this->search->search(
            $term,
            $ctx->user,
            $ctx->workspace,
            [SearchType::WikiPage],
            self::TEXT_SEARCH_CANDIDATES,
        );

        $capped = $matches->isTruncated(SearchType::WikiPage);
        $projectId = $project === null ? null : (int) $project->getKey();
        $rows = [];

        foreach ($matches->for(SearchType::WikiPage) as $result) {
            if ($projectId !== null && $result->projectId !== $projectId) {
                continue;
            }

            $rows[] = [
                'id' => $result->id,
                'title' => $result->title,
                'project_id' => $result->projectId,
                'project' => $result->projectName,
                'slug' => is_string($result->meta['slug'] ?? null) ? $result->meta['slug'] : null,
                'visibility' => is_string($result->meta['visibility'] ?? null) ? $result->meta['visibility'] : null,
                'excerpt' => $result->excerpt,
            ];
        }

        $total = count($rows);
        $data = $this->page(array_slice($rows, 0, $limit), $total, 'pages');

        if ($project !== null) {
            $data['project'] = ['id' => $projectId, 'key' => (string) $project->key];
        }

        if ($capped) {
            $data['text_search_capped'] = true;
            $data['note'] = __('ai.tools.text_search_capped', ['limit' => self::TEXT_SEARCH_CANDIDATES]);
        }

        $data = $this->fit($data, 'pages');

        return ToolResult::ok(
            __('ai.tools.summary.wiki', [
                'returned' => $data['returned'],
                'total' => $data['total'],
                'query' => self::clip($term, 64),
            ]),
            $data,
        );
    }
}
