<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * An archived project was brought back into the active set.
 */
final readonly class ProjectRestored
{
    public function __construct(
        public Project $project,
        public ?User $actor,
    ) {}
}
