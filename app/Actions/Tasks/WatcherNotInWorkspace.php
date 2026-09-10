<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Models\User;
use DomainException;

/**
 * A watcher is notified about a task, so someone outside the workspace must never become
 * one: the notification would describe a record they cannot open.
 */
final class WatcherNotInWorkspace extends DomainException
{
    public function __construct(
        public readonly Task $task,
        public readonly User $watcher,
    ) {
        parent::__construct(__('actions.tasks.watcher_not_in_workspace', ['name' => $watcher->name]));
    }
}
