<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;

/**
 * Project attributes changed. `changes` carries the {attribute: {old, new}} shape the
 * activity feed renders.
 */
final readonly class ProjectUpdated
{
    public function __construct(
        public Project $project,
        /** @var array<string, array{old: mixed, new: mixed}> */
        public array $changes,
        public ?User $actor,
    ) {}
}
