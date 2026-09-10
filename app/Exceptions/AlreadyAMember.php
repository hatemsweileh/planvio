<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Project;
use App\Models\Workspace;

/**
 * Membership tables are unique on (scope, user). Adding somebody twice is a caller mistake
 * rather than a state to reconcile silently: the caller almost certainly meant to change a
 * role instead.
 */
final class AlreadyAMember extends DomainException
{
    public static function ofWorkspace(Workspace $workspace, string $identifier): self
    {
        return new self(
            __(':identifier is already a member of this workspace.', ['identifier' => $identifier]),
            [
                'scope' => 'workspace',
                'workspace_id' => (int) $workspace->getKey(),
                'identifier' => $identifier,
            ],
        );
    }

    public static function ofProject(Project $project, int $userId): self
    {
        return new self(
            __('That person is already a member of the project.'),
            [
                'scope' => 'project',
                'project_id' => (int) $project->getKey(),
                'user_id' => $userId,
            ],
        );
    }
}
