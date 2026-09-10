<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Milestone;
use DomainException;

/**
 * Milestones belong to a project and their progress is rolled up from the tasks pointing at
 * them, so a task may only sit on a milestone of its own project.
 */
final class MilestoneNotInProject extends DomainException
{
    public function __construct(
        public readonly Milestone $milestone,
        public readonly int $projectId,
    ) {
        parent::__construct(__('actions.tasks.milestone_not_in_project'));
    }
}
