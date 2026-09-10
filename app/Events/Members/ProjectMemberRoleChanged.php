<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;

/**
 * A project role moved. The manager role is what the capability matrix marks `+`, so a
 * change here widens or narrows what the person may do inside this project.
 */
final readonly class ProjectMemberRoleChanged
{
    public function __construct(
        public ProjectMember $member,
        public Project $project,
        public ProjectRole $from,
        public ProjectRole $to,
        public ?User $actor,
    ) {}
}
