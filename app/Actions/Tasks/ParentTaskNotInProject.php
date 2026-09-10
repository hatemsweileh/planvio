<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use DomainException;

/**
 * A subtask is displayed inside its parent and inherits the parent's board, so the two must
 * live in the same project.
 */
final class ParentTaskNotInProject extends DomainException
{
    public function __construct(
        public readonly Task $parent,
        public readonly int $projectId,
    ) {
        parent::__construct(__('actions.tasks.parent_not_in_project'));
    }
}
