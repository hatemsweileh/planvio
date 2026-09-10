<?php

declare(strict_types=1);

namespace App\Events\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A saved view was pinned to, or unpinned from, the sidebar.
 */
final class SavedViewPinned implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly SavedView $view,
        public readonly User $actor,
        public readonly bool $pinned,
    ) {}
}
