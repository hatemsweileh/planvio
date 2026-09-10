<?php

declare(strict_types=1);

namespace App\Events\Milestones;

use App\Models\Milestone;
use App\Models\User;

/**
 * A milestone was added to a project.
 */
final readonly class MilestoneCreated
{
    public function __construct(
        public Milestone $milestone,
        public ?User $actor,
    ) {}
}
