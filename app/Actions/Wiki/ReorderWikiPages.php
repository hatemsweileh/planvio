<?php

declare(strict_types=1);

namespace App\Actions\Wiki;

use App\Events\Wiki\WikiPagesReordered;
use App\Exceptions\InvalidWikiPage;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Put the pages under one parent into a given order.
 *
 * Reordering is a single operation on one sibling list, not a bulk edit: every id has to
 * belong to the same workspace and sit under the same parent, or the caller has sent a
 * drag-and-drop payload from a stale page and would silently move somebody else's pages.
 *
 * Ids the caller did not mention keep their relative order and follow the listed ones, so a
 * partial payload — the visible slice of a long sidebar — cannot shuffle what is off-screen.
 */
final class ReorderWikiPages
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly EventDispatcher $events,
    ) {}

    /**
     * @param list<int> $pageIds the sibling ids in their new order
     * @return Collection<int, WikiPage> the siblings, ordered as stored
     */
    public function __invoke(
        Workspace $workspace,
        User $actor,
        array $pageIds,
        ?WikiPage $parent = null,
    ): Collection {
        $workspaceId = (int) $workspace->getKey();
        $parentId = $parent === null ? null : (int) $parent->getKey();

        $requested = array_values(array_unique(array_map(intval(...), $pageIds)));

        if ($requested === []) {
            return $this->siblings($workspaceId, $parentId);
        }

        $pages = WikiPage::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $requested)
            ->get();

        if ($pages->count() !== count($requested)) {
            throw InvalidWikiPage::reorderMixedParents($requested);
        }

        foreach ($pages as $page) {
            $pageParentId = $page->parent_id === null ? null : (int) $page->parent_id;

            if ($pageParentId !== $parentId) {
                throw InvalidWikiPage::reorderMixedParents($requested);
            }
        }

        // The feed entry hangs off the branch that was reordered: the parent when there is
        // one, otherwise the first page in the new order.
        $subject = $parent ?? $pages->firstWhere('id', $requested[0]) ?? $pages->firstOrFail();

        DB::transaction(function () use ($requested, $workspaceId, $parentId, $actor, $subject): void {
            $position = 0;

            foreach ($requested as $id) {
                WikiPage::withoutWorkspaceScope()
                    ->where('workspace_id', $workspaceId)
                    ->whereKey($id)
                    ->update(['position' => $position++, 'updated_at' => now()]);
            }

            // Whatever was not listed keeps its order and lands after the listed pages.
            $remaining = $this->siblings($workspaceId, $parentId)
                ->reject(static fn (WikiPage $page): bool => in_array((int) $page->getKey(), $requested, true));

            foreach ($remaining as $page) {
                $page->position = $position++;
                $page->save();
            }

            $this->activity->forUser($actor)->log($subject, 'wiki_reordered', [
                'parent_id' => $parentId,
                'wiki_page_ids' => $requested,
            ]);
        });

        $this->events->dispatch(new WikiPagesReordered($workspace, $parentId, $requested, $actor));

        return $this->siblings($workspaceId, $parentId);
    }

    /**
     * @return Collection<int, WikiPage>
     */
    private function siblings(int $workspaceId, ?int $parentId): Collection
    {
        $query = WikiPage::withoutWorkspaceScope()
            ->where('workspace_id', $workspaceId);

        $query = $parentId === null
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parentId);

        /** @var Collection<int, WikiPage> $pages */
        $pages = $query->orderBy('position')->orderBy('id')->get();

        return $pages;
    }
}
