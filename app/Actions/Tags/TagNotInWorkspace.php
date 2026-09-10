<?php

declare(strict_types=1);

namespace App\Actions\Tags;

use DomainException;

/**
 * Tags are workspace-wide (`unique(workspace_id, slug)`), and `taggables` carries no tenant
 * column of its own. Attaching a tag from another workspace would make the join the only
 * place the boundary is crossed, and nothing downstream would notice.
 */
final class TagNotInWorkspace extends DomainException
{
    public function __construct(
        public readonly int $tagId,
        public readonly int $workspaceId,
    ) {
        parent::__construct(__('actions.tags.not_in_workspace'));
    }
}
