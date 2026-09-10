<?php

declare(strict_types=1);

namespace App\Events\Views;

use App\Models\SavedView;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A list, board, calendar or timeline configuration was saved.
 */
final class SavedViewCreated implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly SavedView $view,
        public readonly User $actor,
    ) {}
}
