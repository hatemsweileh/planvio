<?php

declare(strict_types=1);

namespace App\Events\Milestones;

use App\Models\Milestone;
use App\Models\User;

/**
 * A milestone was marked complete. Fired once: completing an already-completed milestone
 * is a no-op.
 */
final readonly class MilestoneCompleted
{
    public function __construct(
        public Milestone $milestone,
        public ?User $actor,
    ) {}
}
