<?php

declare(strict_types=1);

namespace App\Actions\Wiki;

use App\Events\Wiki\WikiPageDeleted;
use App\Models\User;
use App\Models\WikiPage;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Remove a wiki page.
 *
 * Its children are lifted to the deleted page's own parent rather than deleted with it.
 * `wiki_pages.parent_id` is `nullOnDelete` at the database level, which only fires on a
 * hard delete — a soft delete would leave the children pointing at a row nothing renders,
 * so they would vanish from the sidebar while still existing. Reparenting keeps every page
 * somebody wrote reachable, and makes the deletion undoable without a cascade to reverse.
 */
final class DeleteWikiPage
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    public function __invoke(WikiPage $page, User $actor): WikiPage
    {
        if ($page->trashed()) {
            return $page;
        }

        $reparented = DB::transaction(function () use ($page, $actor): array {
            $children = WikiPage::withoutWorkspaceScope()
                ->where('workspace_id', $page->workspace_id)
                ->where('parent_id', $page->getKey())
                ->get();

            $reparented = [];

            foreach ($children as $child) {
                $child->parent_id = $page->parent_id;
                $child->save();

                $reparented[] = (int) $child->getKey();
            }

            $page->delete();

            $this->activity->forUser($actor)->log($page, 'deleted', [
                'title' => (string) $page->title,
                'project_id' => $page->project_id === null ? null : (int) $page->project_id,
                'reparented_child_ids' => $reparented,
            ]);

            return $reparented;
        });

        $this->events->dispatch(new WikiPageDeleted($page, $actor, $reparented));

        return $page;
    }
}
