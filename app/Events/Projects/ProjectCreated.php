<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * A project exists with its board columns seeded and its owner already a project manager.
 */
final readonly class ProjectCreated
{
    public function __construct(
        public Project $project,
        public User $owner,
    ) {}
}
