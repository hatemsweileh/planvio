<?php

declare(strict_types=1);

namespace App\Events\Workspaces;

use App\Models\User;
use App\Models\Workspace;

/**
 * A new tenant exists and is ready to use: statuses, tags and the AI settings row
 * were all seeded in the same transaction that created it.
 */
final readonly class WorkspaceCreated
{
    public function __construct(
        public Workspace $workspace,
        public User $owner,
    ) {}
}
