<?php

declare(strict_types=1);

namespace App\Ai\Tools\Write;

use App\Actions\Wiki\CreateWikiPage;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Contracts\AiTool;
use App\Ai\Tools\Concerns\Arguments;
use App\Ai\Tools\Concerns\MutatesThroughActions;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\WikiVisibility;
use App\Models\Project;
use App\Models\WikiPage;

/**
 * Write a new wiki page.
 *
 * `ai_generated` is always true and is not an argument. A wiki is the place a team goes to
 * find out what is true, and a page written by a model that reads exactly like one written by
 * a colleague is the most quietly corrosive thing the agent could produce: nobody knows to
 * check it. The column is what the UI renders the badge from, so there is no call that makes
 * an agent-written page indistinguishable from a person's.
 *
 * The body is sanitised by {@see CreateWikiPage} before storage and the excerpt is derived
 * from the sanitised body as plain text, so a search result or a sidebar preview can never
 * render markup the model produced. The slug is deduplicated inside the page's own scope, so
 * two "Getting started" pages coexist rather than one of them failing on a unique index.
 */
final class CreateDocumentTool implements AiTool
{
    use MutatesThroughActions;

    public function __construct(private readonly CreateWikiPage $createWikiPage) {}

    public function name(): string
    {
        return 'create_document';
    }

    public function group(): string
    {
        return 'wiki';
    }

    public function description(): string
    {
        return 'Write a new wiki page, in a project or at workspace level. The page is marked '
            .'as AI-generated and shown that way to readers. Content is sanitised on the way '
            .'in. A parent page must be in the same project.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title'],
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                'content' => [
                    'type' => ['string', 'null'],
                    'maxLength' => 100000,
                    'description' => 'The page body. Simple HTML or plain text; it is sanitised on the way in.',
                ],
                'project_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'File the page under a project. Omit for a workspace-level page.',
                ],
                'parent_page_id' => [
                    'type' => ['integer', 'null'],
                    'minimum' => 1,
                    'description' => 'Nest under an existing page. It must sit in the same project.',
                ],
                'visibility' => [
                    'type' => 'string',
                    'enum' => ['project', 'workspace', 'private'],
                    'description' => 'Defaults to project.',
                ],
                'excerpt' => ['type' => ['string', 'null'], 'maxLength' => 255],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Low;
    }

    public function permission(): ?Permission
    {
        return Permission::WikiManage;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle($args, $ctx, fn (Arguments $in, AgentContext $ctx): ToolResult => $this->write($in, $ctx));
    }

    private function write(Arguments $in, AgentContext $ctx): ToolResult
    {
        $project = null;

        if ($in->filled('project_id')) {
            $projectId = $in->int('project_id');
            $project = $this->resolveProject($projectId, $ctx);

            if (! $project instanceof Project) {
                return $this->notFound(__('project'), $projectId);
            }

            $ctx->assertInWorkspace($project);
        }

        // Asked against the project the page will live in, not the one the run happens to be
        // focused on: a workspace-level page is a different authorization question.
        if (! $ctx->allows('create', $project instanceof Project ? [WikiPage::class, $project] : [WikiPage::class])) {
            return $this->denied($ctx, $project instanceof Project
                ? __('write wiki pages in :project', ['project' => $project->name])
                : __('write workspace-level wiki pages'));
        }

        $parent = null;

        if ($in->filled('parent_page_id')) {
            $parentId = $in->int('parent_page_id');
            $parent = $this->resolveWikiPage($parentId, $ctx);

            if (! $parent instanceof WikiPage) {
                return $this->notFound(__('wiki page'), $parentId);
            }

            $ctx->assertInWorkspace($parent);
        }

        $page = ($this->createWikiPage)(
            workspace: $ctx->workspace,
            author: $ctx->user,
            title: (string) $in->text('title'),
            content: $in->text('content'),
            project: $project,
            parent: $parent,
            visibility: $in->enum('visibility', WikiVisibility::class) ?? WikiVisibility::Project,
            excerpt: $in->text('excerpt'),
            aiGenerated: true,
        );

        return ToolResult::ok(
            __('Created the AI-generated wiki page ":title" :scope.', [
                'title' => self::clip($page->title, 120),
                'scope' => $project instanceof Project
                    ? __('in :project', ['project' => $project->name])
                    : __('at workspace level'),
            ]),
            [
                'wiki_page_id' => (int) $page->getKey(),
                'title' => $page->title,
                'slug' => $page->slug,
                'project_id' => $project === null ? null : (int) $project->getKey(),
                'parent_page_id' => $parent === null ? null : (int) $parent->getKey(),
                'visibility' => $page->visibility->value,
                'ai_generated' => true,
                'author' => $ctx->user->name,
                'excerpt' => self::clip($page->excerpt, 200),
            ],
            $page,
        );
    }
}
