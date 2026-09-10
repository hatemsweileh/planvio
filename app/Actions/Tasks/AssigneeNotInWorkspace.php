<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\User;
use DomainException;

/**
 * Assigning work to someone who is not in the workspace would hand them a task they cannot
 * open. This is a domain invariant, not an authorization check: whether the *actor* may
 * assign at all is the caller's question, asked before the action is ever reached.
 */
final class AssigneeNotInWorkspace extends DomainException
{
    public function __construct(
        public readonly int $workspaceId,
        public readonly User $assignee,
    ) {
        parent::__construct(__('actions.tasks.assignee_not_in_workspace', ['name' => $assignee->name]));
    }
}
