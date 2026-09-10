<?php

declare(strict_types=1);

namespace App\Events\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A saved view was discarded. `saved_views` has no soft deletes, so this model is the last
 * copy of the row.
 */
final class SavedViewDeleted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly SavedView $view,
        public readonly User $actor,
    ) {}
}
