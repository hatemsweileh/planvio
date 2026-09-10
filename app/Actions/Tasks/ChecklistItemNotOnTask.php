<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\TaskChecklistItem;
use DomainException;

/**
 * Checklist items carry no `workspace_id`; their tenancy is the task they hang from. An
 * item reordered or edited against the wrong task would cross that boundary unnoticed.
 */
final class ChecklistItemNotOnTask extends DomainException
{
    public function __construct(
        public readonly TaskChecklistItem $item,
        public readonly Task $task,
    ) {
        parent::__construct(__('actions.tasks.checklist_item_not_on_task'));
    }
}
