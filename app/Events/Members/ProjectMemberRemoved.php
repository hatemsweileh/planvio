<?php

declare(strict_types=1);

namespace App\Events\Members;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;

/**
 * Explicit project access was withdrawn. A workspace member above guest may still see
 * the project through their workspace role.
 */
final readonly class ProjectMemberRemoved
{
    public function __construct(
        public Project $project,
        public User $user,
        public ProjectRole $previousRole,
        public ?User $actor,
    ) {}
}
