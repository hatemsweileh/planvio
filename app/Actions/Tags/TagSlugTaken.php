<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use App\Models\Tag;
use DomainException;

/**
 * Renaming a tag onto a slug another tag already holds would violate
 * `unique(workspace_id, slug)`. Caught here so the caller gets the name back rather than a
 * driver-specific integrity error.
 */
final class TagSlugTaken extends DomainException
{
    public function __construct(
        public readonly Tag $tag,
        public readonly string $slug,
        string $name,
    ) {
        parent::__construct(__('actions.tags.slug_taken', ['name' => $name]));
    }
}
