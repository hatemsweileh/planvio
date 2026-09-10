<?php

declare(strict_types=1);

namespace App\Events\Milestones;

use App\Models\Milestone;
use App\Models\User;

/**
 * A milestone was soft deleted and its tasks were detached from it.
 */
final readonly class MilestoneDeleted
{
    public function __construct(
        public Milestone $milestone,
        public int $tasksDetached,
        public ?User $actor,
    ) {}
}
