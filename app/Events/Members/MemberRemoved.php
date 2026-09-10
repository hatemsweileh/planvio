<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

/**
 * A person no longer belongs to the workspace. Their open tasks were either handed to
 * `reassignedTo` or left unassigned, which `tasksReassigned` counts either way.
 */
final readonly class MemberRemoved
{
    public function __construct(
        public Workspace $workspace,
        public User $user,
        public WorkspaceRole $previousRole,
        public int $tasksReassigned,
        public ?User $reassignedTo,
        public ?User $actor,
    ) {}
}
