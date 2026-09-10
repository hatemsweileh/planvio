<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Project;
use DomainException;

/**
 * A project with no board columns cannot hold a task: `tasks.status_id` is not nullable.
 * Projects are seeded with columns on creation, so reaching this means the board was
 * emptied out from under the caller.
 */
final class NoTaskStatusAvailable extends DomainException
{
    public function __construct(public readonly Project $project)
    {
        parent::__construct(__('actions.tasks.no_status_available', ['project' => $project->name]));
    }
}
