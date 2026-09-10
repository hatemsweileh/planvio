<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Project;
use App\Models\Workspace;

/**
 * The subject of a membership operation does not belong to the scope it is being changed in.
 *
 * This is an invariant, not an authorization check: whether the caller may act was already
 * settled by a policy. What failed is that the record being pointed at does not exist — a
 * role cannot be changed for somebody who never joined, and a task cannot be reassigned to
 * somebody outside the workspace.
 */
final class NotAMember extends DomainException
{
    public static function ofWorkspace(Workspace $workspace, int $userId): self
    {
        return new self(
            __('That person is not a member of this workspace.'),
            [
                'scope' => 'workspace',
                'workspace_id' => (int) $workspace->getKey(),
                'user_id' => $userId,
            ],
        );
    }

    public static function ofProject(Project $project, int $userId): self
    {
        return new self(
            __('That person is not a member of this project.'),
            [
                'scope' => 'project',
                'project_id' => (int) $project->getKey(),
                'user_id' => $userId,
            ],
        );
    }
}
