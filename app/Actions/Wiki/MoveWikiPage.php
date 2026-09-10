<?php

declare(strict_types=1);

namespace App\Actions\Wiki;

use App\Events\Wiki\WikiPageMoved;
use App\Exceptions\InvalidWikiPage;
use App\Exceptions\WorkspaceMismatch;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * File a page somewhere else: under a different parent, or in a different project.
 *
 * Two invariants make this more than an UPDATE.
 *
 * A page may not be filed under its own descendant. The tree is walked upwards from the
 * proposed parent before anything is written, because a cycle here is not a cosmetic
 * problem: `breadcrumb()`, the sidebar and any recursive delete all follow parent links and
 * would run until they exhaust memory.
 *
 * A subtree moves with its root. A page and its children belong to one project, so changing
 * the project rewrites every descendant too — leaving them behind would put pages in a
 * project whose parent lives in another one, and their slugs would still be reserved in the
 * old project's namespace.
 */
final class MoveWikiPage
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(
        WikiPage $page,
        User $actor,
        ?WikiPage $parent,
        ?Project $project,
        ?int $position = null,
    ): WikiPage {
        $workspaceId = (int) $page->workspace_id;

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
            if ((int) $parent->getKey() === (int) $page->getKey()) {
                throw InvalidWikiPage::selfParent($page);
            }

            self::assertNotADescendant($page, $parent);
            CreateWikiPage::assertParentIsUsable($parent, $workspaceId, $projectId);
        }

        $fromParentId = $page->parent_id === null ? null : (int) $page->parent_id;
        $fromProjectId = $page->project_id === null ? null : (int) $page->project_id;

        if ($fromParentId === ($parent === null ? null : (int) $parent->getKey())
            && $fromProjectId === $projectId
            && ($position === null || (int) $page->position === $position)) {
            return $page;
        }

        $moved = DB::transaction(function () use (
            $page,
            $actor,
            $parent,
            $projectId,
            $position,
            $workspaceId,
            $fromParentId,
            $fromProjectId,
        ): WikiPage {
            $page->parent_id = $parent?->getKey();
            $page->project_id = $projectId;
            $page->position = $position ?? CreateWikiPage::nextPosition($workspaceId, $projectId, $parent);

            if ($fromProjectId !== $projectId) {
                $page->slug = WikiSlug::unique(
                    (string) $page->title,
                    $workspaceId,
                    $projectId,
                    (int) $page->getKey(),
                );
            }

            $page->save();

            $descendants = $fromProjectId === $projectId
                ? []
                : $this->moveDescendants($page, $workspaceId, $projectId);

            $this->activity->forUser($actor)->log($page, 'moved', [
                'from_parent_id' => $fromParentId,
                'to_parent_id' => $parent === null ? null : (int) $parent->getKey(),
                'from_project_id' => $fromProjectId,
                'to_project_id' => $projectId,
                'descendants_moved' => count($descendants),
            ]);

            return $page;
        });

        $this->events->dispatch(new WikiPageMoved($moved, $actor, $fromParentId, $fromProjectId));

        return $moved->refresh();
    }

    /**
     * Walk up from the proposed parent. Reaching the page means the move would close a
     * loop; the walk is also bounded, because the tree it is checking may already be
     * broken by a bug or a hand-edited row.
     */
    private static function assertNotADescendant(WikiPage $page, WikiPage $parent): void
    {
        $pageId = (int) $page->getKey();
        $path = [];
        $seen = [];
        $currentId = $parent->parent_id === null ? null : (int) $parent->parent_id;

        $path[] = (int) $parent->getKey();

        while ($currentId !== null) {
            if ($currentId === $pageId) {
                throw InvalidWikiPage::parentCycle($page, $parent, $path);
            }

            if (isset($seen[$currentId])) {
                // The existing tree already loops. Refuse rather than add to it.
                throw InvalidWikiPage::parentCycle($page, $parent, $path);
            }

            $seen[$currentId] = true;
            $path[] = $currentId;

            $ancestor = WikiPage::withoutWorkspaceScope()
                ->withTrashed()
                ->select(['id', 'parent_id'])
                ->find($currentId);

            $currentId = $ancestor?->parent_id === null ? null : (int) $ancestor->parent_id;
        }
    }

    /**
     * Rewrite the project of everything below the moved page, re-slugging each one into the
     * destination namespace.
     *
     * @return list<int>
     */
    private function moveDescendants(WikiPage $page, int $workspaceId, ?int $projectId): array
    {
        $moved = [];
        $frontier = [(int) $page->getKey()];
        $guard = 0;

        while ($frontier !== [] && $guard++ < 1000) {
            $children = WikiPage::withoutWorkspaceScope()
                ->withTrashed()
                ->where('workspace_id', $workspaceId)
                ->whereIn('parent_id', $frontier)
                ->get();

            if ($children->isEmpty()) {
                break;
            }

            $frontier = [];

            foreach ($children as $child) {
                $child->project_id = $projectId;
                $child->slug = WikiSlug::unique(
                    (string) $child->title,
                    $workspaceId,
                    $projectId,
                    (int) $child->getKey(),
                );
                $child->save();

                $moved[] = (int) $child->getKey();
                $frontier[] = (int) $child->getKey();
            }
        }

        return $moved;
    }
}
