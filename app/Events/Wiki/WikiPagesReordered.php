<?php

declare(strict_types=1);

namespace App\Events\Wiki;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * The pages under one parent were given a new order.
 */
final class WikiPagesReordered implements ShouldDispatchAfterCommit
{
    /**
     * @param list<int> $pageIds in their new order
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?int $parentId,
        public readonly array $pageIds,
        public readonly User $actor,
    ) {}
}
