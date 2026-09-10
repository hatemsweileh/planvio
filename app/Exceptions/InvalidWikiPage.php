<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\WikiPage;

/**
 * A wiki page cannot be written or moved as asked.
 *
 * The tree is user-editable and self-referential, so the cycle check is not a nicety: a
 * page filed under its own descendant makes every breadcrumb walk, every sidebar render
 * and every recursive delete run forever.
 */
final class InvalidWikiPage extends DomainException
{
    public static function titleRequired(): self
    {
        return new self(__('actions.wiki.title_required'));
    }

    public static function selfParent(WikiPage $page): self
    {
        return new self(
            __('actions.wiki.self_parent'),
            ['wiki_page_id' => (int) $page->getKey()],
        );
    }

    /**
     * @param list<int> $path the ancestor ids walked before the loop was found
     */
    public static function parentCycle(WikiPage $page, WikiPage $parent, array $path): self
    {
        return new self(
            __('actions.wiki.parent_cycle'),
            [
                'wiki_page_id' => (int) $page->getKey(),
                'parent_id' => (int) $parent->getKey(),
                'path' => $path,
            ],
        );
    }

    public static function parentInAnotherProject(WikiPage $parent, ?int $projectId): self
    {
        return new self(
            __('actions.wiki.parent_in_another_project'),
            [
                'parent_id' => (int) $parent->getKey(),
                'parent_project_id' => $parent->project_id === null ? null : (int) $parent->project_id,
                'project_id' => $projectId,
            ],
        );
    }

    /**
     * @param list<int> $pageIds
     */
    public static function reorderMixedParents(array $pageIds): self
    {
        return new self(
            __('actions.wiki.reorder_mixed_parents'),
            ['wiki_page_ids' => $pageIds],
        );
    }
}
