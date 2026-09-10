<?php

declare(strict_types=1);

namespace App\Events\Milestones;

use App\Models\Milestone;
use App\Models\User;

/**
 * Milestone attributes changed. `changes` carries the {attribute: {old, new}} shape the
 * activity feed renders.
 */
final readonly class MilestoneUpdated
{
    public function __construct(
        public Milestone $milestone,
        /** @var array<string, array{old: mixed, new: mixed}> */
        public array $changes,
        public ?User $actor,
    ) {}
}
