<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * The project was archived: still readable, out of every default list, and no longer
 * counted in workspace roll-ups.
 */
final readonly class ProjectArchived
{
    public function __construct(
        public Project $project,
        public ?User $actor,
    ) {}
}
