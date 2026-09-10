<?php

declare(strict_types=1);

namespace App\Events\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A saved view's configuration changed.
 */
final class SavedViewUpdated implements ShouldDispatchAfterCommit
{
    /**
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    public function __construct(
        public readonly SavedView $view,
        public readonly User $actor,
        public readonly array $changes = [],
    ) {}
}
