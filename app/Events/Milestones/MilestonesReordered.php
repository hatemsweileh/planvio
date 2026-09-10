<?php

declare(strict_types=1);

namespace App\Events\Milestones;

use App\Models\Project;
use App\Models\User;

/**
 * The milestones of a project were given new positions. `orderedIds` is the resulting
 * order, front to back.
 */
final readonly class MilestonesReordered
{
    public function __construct(
        public Project $project,
        /** @var list<int> */
        public array $orderedIds,
        public ?User $actor,
    ) {}
}
