<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;

/**
 * A workspace role moved. Both ends are carried because the direction decides what a
 * listener should do: a demotion may need to drop cached capabilities.
 */
final readonly class MemberRoleChanged
{
    public function __construct(
        public WorkspaceMember $member,
        public WorkspaceRole $from,
        public WorkspaceRole $to,
        public ?User $actor,
    ) {}
}
