<?php

declare(strict_types=1);

namespace App\Events\Workspaces;

use App\Models\User;
use App\Models\Workspace;

/**
 * A user moved their session into another workspace they belong to.
 */
final readonly class WorkspaceSwitched
{
    public function __construct(
        public Workspace $workspace,
        public User $user,
    ) {}
}
