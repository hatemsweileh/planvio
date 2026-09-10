<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Project;
use App\Models\Workspace;

/**
 * A saved view cannot be written as asked.
 */
final class InvalidSavedView extends DomainException
{
    public static function nameRequired(): self
    {
        return new self(__('actions.views.name_required'));
    }

    public static function projectInAnotherWorkspace(Project $project, Workspace $workspace): self
    {
        return new self(
            __('actions.views.project_in_another_workspace'),
            [
                'project_id' => (int) $project->getKey(),
                'project_workspace_id' => (int) $project->workspace_id,
                'workspace_id' => (int) $workspace->getKey(),
            ],
        );
    }
}
