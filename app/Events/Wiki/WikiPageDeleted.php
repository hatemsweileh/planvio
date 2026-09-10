<?php

declare(strict_types=1);

namespace App\Events\Wiki;

use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wiki page was removed.
 *
 * `reparentedChildIds` names the pages that were lifted to the deleted page's parent, so
 * nothing was left unreachable behind it.
 */
final class WikiPageDeleted implements ShouldDispatchAfterCommit
{
    /**
     * @param list<int> $reparentedChildIds
     */
    public function __construct(
        public readonly WikiPage $page,
        public readonly User $actor,
        public readonly array $reparentedChildIds = [],
    ) {}
}
