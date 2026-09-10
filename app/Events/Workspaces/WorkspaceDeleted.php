<?php

declare(strict_types=1);

namespace App\Events\Workspaces;

use App\Models\User;
use App\Models\Workspace;

/**
 * The workspace was soft deleted. Its rows are still present and still tenant-scoped;
 * nothing has been erased.
 */
final readonly class WorkspaceDeleted
{
    public function __construct(
        public Workspace $workspace,
        public ?User $actor,
    ) {}
}
