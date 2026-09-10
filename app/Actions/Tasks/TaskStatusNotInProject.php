<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Project;
use App\Models\TaskStatus;
use DomainException;

/**
 * Board columns are per project (ARCHITECTURE.md §5.3). Moving a task into a column that
 * belongs to another project would put it on a board it can never be seen on.
 */
final class TaskStatusNotInProject extends DomainException
{
    public function __construct(
        public readonly TaskStatus $status,
        public readonly Project|int $project,
    ) {
        parent::__construct(__('actions.tasks.status_not_in_project'));
    }
}
