<?php

declare(strict_types=1);

namespace App\Events\Wiki;

use App\Models\User;
use App\Models\WikiPage;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A wiki page was edited.
 */
final class WikiPageUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    public function __construct(
        public readonly WikiPage $page,
        public readonly User $actor,
        public readonly array $changes = [],
    ) {}
}
