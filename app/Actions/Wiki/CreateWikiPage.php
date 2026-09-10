<?php

declare(strict_types=1);

namespace App\Actions\Wiki;

use App\Enums\WikiVisibility;
use App\Events\Wiki\WikiPageCreated;
use App\Exceptions\InvalidWikiPage;
use App\Exceptions\WorkspaceMismatch;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Services\HtmlSanitizer;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Write a new wiki page.
 *
 * The body is sanitised before it is stored, exactly as a comment is (§5.6 calls the column
 * "sanitised HTML"), and the excerpt is derived as plain text from the sanitised body so a
 * search result or sidebar preview can never render markup.
 *
 * The slug is chosen to be free inside the page's own scope, so two people writing "Getting
 * started" in the same project get `getting-started` and `getting-started-2` rather than an
 * integrity-constraint error thrown at the second one.
 */
final class CreateWikiPage
{
    private const EXCERPT_CHARS = 200;

    public function __construct(
        private readonly HtmlSanitizer $sanitizer,
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        Workspace $workspace,
        User $author,
        string $title,
        ?string $content = null,
        ?Project $project = null,
        ?WikiPage $parent = null,
        WikiVisibility $visibility = WikiVisibility::Project,
        ?string $excerpt = null,
        bool $aiGenerated = false,
    ): WikiPage {
        $title = trim($title);

        if ($title === '') {
            throw InvalidWikiPage::titleRequired();
        }

        $workspaceId = (int) $workspace->getKey();

        if ($project !== null && (int) $project->workspace_id !== $workspaceId) {
            throw WorkspaceMismatch::between(
                Project::class,
                (int) $project->workspace_id,
                Workspace::class,
                $workspaceId,
            );
        }

        $projectId = $project === null ? null : (int) $project->getKey();

        if ($parent !== null) {
            self::assertParentIsUsable($parent, $workspaceId, $projectId);
        }

        $body = $content === null ? null : $this->sanitizer->sanitize($content);
        $title = mb_substr($title, 0, 255);

        $page = DB::transaction(function () use (
            $workspaceId,
            $author,
            $title,
            $body,
            $projectId,
            $parent,
            $visibility,
            $excerpt,
            $aiGenerated,
        ): WikiPage {
            $page = WikiPage::query()->create([
                'workspace_id' => $workspaceId,
                'project_id' => $projectId,
                'parent_id' => $parent?->getKey(),
                'title' => $title,
                'slug' => WikiSlug::unique($title, $workspaceId, $projectId),
                'content' => $body,
                'excerpt' => $this->excerpt($excerpt, $body),
                'position' => self::nextPosition($workspaceId, $projectId, $parent),
                'visibility' => $visibility,
                'author_id' => $author->getKey(),
                'last_edited_by' => null,
                'ai_generated' => $aiGenerated,
            ]);

            $this->activity->forUser($author)->log($page, 'created', [
                'title' => $title,
                'project_id' => $projectId,
                'parent_id' => $parent === null ? null : (int) $parent->getKey(),
                'visibility' => $visibility->value,
            ]);

            return $page;
        });

        $this->events->dispatch(new WikiPageCreated($page, $author));

        return $page;
    }

    /**
     * A page and its parent share a tree, so they share a project — otherwise the sidebar
     * of one project would list a page belonging to another.
     */
    public static function assertParentIsUsable(WikiPage $parent, int $workspaceId, ?int $projectId): void
    {
        if ((int) $parent->workspace_id !== $workspaceId) {
            throw WorkspaceMismatch::between(
                WikiPage::class,
                (int) $parent->workspace_id,
                Workspace::class,
                $workspaceId,
            );
        }

        $parentProjectId = $parent->project_id === null ? null : (int) $parent->project_id;

        if ($parentProjectId !== $projectId) {
            throw InvalidWikiPage::parentInAnotherProject($parent, $projectId);
        }
    }

    public static function nextPosition(int $workspaceId, ?int $projectId, ?WikiPage $parent): int
    {
        $query = WikiPage::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId);

        $query = $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);

        $query = $parent === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parent->getKey());

        return (int) $query->max('position') + 1;
    }

    private function excerpt(?string $given, ?string $body): ?string
    {
        if ($given !== null && trim($given) !== '') {
            return mb_substr(trim($given), 0, 255);
        }

        if ($body === null || $body === '') {
            return null;
        }

        $derived = $this->sanitizer->sanitizeExcerpt($body, self::EXCERPT_CHARS);

        return $derived === '' ? null : $derived;
    }
}
