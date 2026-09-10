<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * A project was copied. The copy is a normal project from this point on: nothing links
 * it back to its source except this event.
 */
final readonly class ProjectDuplicated
{
    public function __construct(
        public Project $project,
        public Project $source,
        public User $owner,
    ) {}
}
