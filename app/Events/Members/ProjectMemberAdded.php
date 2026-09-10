<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;

/**
 * Somebody was given explicit access to one project. For a workspace guest this is the
 * only thing that makes the project visible at all.
 */
final readonly class ProjectMemberAdded
{
    public function __construct(
        public ProjectMember $member,
        public Project $project,
        public ?User $actor,
    ) {}
}
