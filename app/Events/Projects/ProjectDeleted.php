<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * The project was soft deleted. Tasks, milestones and statuses are untouched and come
 * back with it if it is restored.
 */
final readonly class ProjectDeleted
{
    public function __construct(
        public Project $project,
        public ?User $actor,
    ) {}
}
