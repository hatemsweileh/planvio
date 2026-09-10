<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;

/**
 * The denormalised `progress` cache moved. Fired only on a real change, so a listener
 * can treat it as a milestone-worthy event rather than as periodic noise.
 */
final readonly class ProjectProgressRecalculated
{
    public function __construct(
        public Project $project,
        public int $from,
        public int $to,
    ) {}
}
