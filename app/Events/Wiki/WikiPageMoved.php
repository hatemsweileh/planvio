<?php

declare(strict_types=1);

namespace App\Events\Wiki;

use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wiki page was filed somewhere else in the tree, or moved to another project.
 */
final class WikiPageMoved implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly WikiPage $page,
        public readonly User $actor,
        public readonly ?int $fromParentId,
        public readonly ?int $fromProjectId,
    ) {}
}
