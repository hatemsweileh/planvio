<?php

declare(strict_types=1);

namespace App\Livewire\App\Projects\Concerns;

use App\Actions\Wiki\CreateWikiPage;
use App\Actions\Wiki\DeleteWikiPage;
use App\Actions\Wiki\MoveWikiPage;
use App\Actions\Wiki\ReorderWikiPages;
use App\Enums\WikiVisibility;
use App\Exceptions\DomainException;
use App\Models\Project;
use App\Models\User;
use App\Models\WikiPage;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The document rail, shared by the wiki index and a single page.
 *
 * Both screens draw the same tree and offer the same operations on it, so the tree lives
 * here rather than being written twice and drifting.
 *
 * On drag-and-drop: each level of the tree is its own `sortableList`, which reports the ids
 * of the list the drag started in. That is exactly right for reordering siblings — the
 * parent is implied by the rows themselves and re-derived server-side — and it is why a
 * drag is deliberately confined to one level: a cross-level drop would be reported by the
 * list the page *left*, which cannot say where it landed. Re-parenting is therefore an
 * explicit "Move under" choice on the row, which is also the only version of the gesture a
 * keyboard can perform.
 *
 * @property-read Workspace $workspace
 * @property-read Project $project
 */
trait ManagesWikiTree
{
    /** A tree this deep stops being navigable; the rail refuses to draw further. */
    private const MAX_DEPTH = 6;

    /** A rail is a rail, not a report. Beyond this the search box is the right tool. */
    private const MAX_PAGES = 400;

    public bool $showCreate = false;

    public string $newTitle = '';

    public ?int $newParentId = null;

    public string $newVisibility = 'project';

    public string $treeSearch = '';

    /**
     * Every page of this project the acting user may read, keyed by parent id.
     *
     * One query builds the whole rail. Grouping in PHP rather than issuing a query per
     * level is what keeps a deep tree from turning into a query storm.
     *
     * @return Collection<int|string, Collection<int, WikiPage>>
     */
    #[Computed]
    public function tree(): Collection
    {
        $term = trim($this->treeSearch);

        $pages = WikiPage::query()
            ->forProject($this->project)
            ->visibleTo($this->actor())
            ->ordered()
            ->limit(self::MAX_PAGES)
            ->get(['id', 'parent_id', 'title', 'slug', 'visibility', 'ai_generated', 'position', 'updated_at']);

        if ($term !== '') {
            $matches = $pages->filter(
                fn (WikiPage $page): bool => mb_stripos((string) $page->title, $term) !== false,
            );

            // A match is useless without the path that reaches it, so every ancestor of a
            // hit is kept even when its own title does not match.
            $keep = [];

            foreach ($matches as $match) {
                $cursor = $match;

                while ($cursor instanceof WikiPage && ! isset($keep[(int) $cursor->getKey()])) {
                    $keep[(int) $cursor->getKey()] = true;
                    $parentId = $cursor->parent_id === null ? null : (int) $cursor->parent_id;
                    $cursor = $parentId === null ? null : $pages->firstWhere('id', $parentId);
                }
            }

            $pages = $pages->filter(fn (WikiPage $page): bool => isset($keep[(int) $page->getKey()]));
        }

        return $pages->groupBy(fn (WikiPage $page): string => (string) ($page->parent_id ?? 'root'));
    }

    #[Computed]
    public function pageCount(): int
    {
        return WikiPage::query()
            ->forProject($this->project)
            ->visibleTo($this->actor())
            ->count();
    }

    /**
     * Candidate parents for the "Move under" menu, as id => indented title.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function parentOptions(): array
    {
        $options = [];

        $this->collectParents($this->tree(), 'root', 0, $options);

        return $options;
    }

    /* ------------------------------------------------------------------ *
     * Writes
     * ------------------------------------------------------------------ */

    public function startCreate(?int $parentId = null): void
    {
        $this->authorize('create', [WikiPage::class, $this->project]);

        $this->newParentId = $parentId;
        $this->newTitle = '';
        $this->newVisibility = WikiVisibility::Project->value;
        $this->showCreate = true;

        $this->resetValidation();
    }

    public function createPage(CreateWikiPage $createWikiPage): void
    {
        $this->authorize('create', [WikiPage::class, $this->project]);

        $data = $this->validate([
            'newTitle' => ['required', 'string', 'min:1', 'max:255'],
            'newParentId' => ['nullable', 'integer'],
            'newVisibility' => ['required', 'string', 'in:project,workspace,private'],
        ]);

        $parent = $this->resolvePage($data['newParentId'] === null ? null : (int) $data['newParentId']);

        try {
            $page = $createWikiPage(
                workspace: $this->workspace,
                author: $this->actor(),
                title: $data['newTitle'],
                content: null,
                project: $this->project,
                parent: $parent,
                visibility: WikiVisibility::from($data['newVisibility']),
            );
        } catch (DomainException $failure) {
            $this->addError('newTitle', $failure->userMessage());

            return;
        }

        $this->showCreate = false;
        $this->newTitle = '';

        $this->redirect(
            route('app.projects.wiki.show', [$this->workspace, $this->project, $page]),
            navigate: true,
        );
    }

    /**
     * Reorder the rail after a drag.
     *
     * The browser reports the ids under the list the drag started in, in document order —
     * which, because a level's children are drawn inside it, is the whole subtree rather
     * than one row of siblings. That is fine and is why nothing here trusts the shape of
     * the list: the ids are grouped by the parent each page *actually* has, and each group
     * is reordered by its own relative order within the report. Depth-first order preserves
     * sibling order at every level, so one drag produces one changed group and the rest are
     * recognised as unchanged and left alone.
     *
     * @param array<int, int|string> $ids
     */
    public function reorderWikiPages(array $ids, ReorderWikiPages $reorderWikiPages): void
    {
        $ids = array_values(array_filter(array_map(intval(...), $ids)));

        if ($ids === []) {
            return;
        }

        /** @var Collection<int, WikiPage> $pages */
        $pages = WikiPage::query()
            ->forProject($this->project)
            ->whereIn('id', $ids)
            ->ordered()
            ->get()
            ->keyBy(fn (WikiPage $page): int => (int) $page->getKey());

        if ($pages->isEmpty()) {
            return;
        }

        /** @var array<string, list<int>> $requested */
        $requested = [];

        foreach ($ids as $id) {
            $page = $pages->get($id);

            if (! $page instanceof WikiPage) {
                continue;
            }

            $requested[(string) ($page->parent_id ?? 'root')][] = $id;
        }

        $changed = false;

        foreach ($requested as $key => $group) {
            $current = $pages
                ->filter(fn (WikiPage $page): bool => (string) ($page->parent_id ?? 'root') === $key)
                ->map(fn (WikiPage $page): int => (int) $page->getKey())
                ->values()
                ->all();

            if ($current === $group) {
                continue;
            }

            foreach ($group as $id) {
                $this->authorize('move', $pages->get($id));
            }

            try {
                $reorderWikiPages(
                    workspace: $this->workspace,
                    actor: $this->actor(),
                    pageIds: $group,
                    parent: $key === 'root' ? null : $this->resolvePage((int) $key),
                );
            } catch (DomainException $failure) {
                $this->dispatch('planvio-notify', type: 'error', message: $failure->userMessage());

                continue;
            }

            $changed = true;
        }

        if ($changed) {
            unset($this->tree, $this->parentOptions);
        }
    }

    public function movePage(int $pageId, ?int $parentId, MoveWikiPage $moveWikiPage): void
    {
        $page = $this->resolvePage($pageId);

        if ($page === null) {
            return;
        }

        $this->authorize('move', $page);

        $parent = $this->resolvePage($parentId);

        if ($parent !== null) {
            $this->authorize('view', $parent);
        }

        try {
            $moveWikiPage(
                page: $page,
                actor: $this->actor(),
                parent: $parent,
                project: $this->project,
            );
        } catch (DomainException $failure) {
            $this->dispatch('planvio-notify', type: 'error', message: $failure->userMessage());

            return;
        }

        unset($this->tree, $this->parentOptions);

        $this->dispatch('planvio-notify', type: 'success', message: __('Page moved.'));
    }

    public function deletePage(int $pageId, DeleteWikiPage $deleteWikiPage): void
    {
        $page = $this->resolvePage($pageId);

        if ($page === null) {
            return;
        }

        $this->authorize('delete', $page);

        $deleteWikiPage($page, $this->actor());

        unset($this->tree, $this->parentOptions, $this->pageCount);

        $this->dispatch('planvio-notify', type: 'success', message: __('“:title” was deleted.', [
            'title' => $page->title,
        ]));

        $this->afterPageDeleted($page);
    }

    /**
     * What the screen does once a page is gone. The index simply redraws; the page view has
     * to leave, because the record it was showing no longer exists.
     */
    protected function afterPageDeleted(WikiPage $page): void
    {
        //
    }

    /* ------------------------------------------------------------------ *
     * Internals
     * ------------------------------------------------------------------ */

    protected function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    protected function resolvePage(?int $pageId): ?WikiPage
    {
        if ($pageId === null) {
            return null;
        }

        return WikiPage::query()
            ->forProject($this->project)
            ->whereKey($pageId)
            ->first();
    }

    /**
     * @param Collection<int|string, Collection<int, WikiPage>> $tree
     * @param array<int, string> $options
     */
    private function collectParents(Collection $tree, string $key, int $depth, array &$options): void
    {
        if ($depth >= self::MAX_DEPTH) {
            return;
        }

        foreach ($tree->get($key, collect()) as $page) {
            $options[(int) $page->getKey()] = str_repeat('— ', $depth).$page->title;

            $this->collectParents($tree, (string) $page->getKey(), $depth + 1, $options);
        }
    }
}
