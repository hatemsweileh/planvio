<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Task;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates a task hanging under another one.
 *
 * A subtask is an ordinary task with a parent — same board, same numbering, same
 * everything — so the work is done by {@see CreateTask} and this action only adds the two
 * things that are specific to the relationship: the parent must be in the same project, and
 * the parent's own feed should say that a subtask appeared under it.
 */
final class CreateSubtask
{
    public function __construct(
        private readonly CreateTask $createTask,
        private readonly ActivityLogger $activity,
    ) {}

    public function __invoke(Task $parent, CreateTaskData $data): Task
    {
        if ((int) $parent->project_id !== (int) $data->project->getKey()) {
            throw new ParentTaskNotInProject($parent, (int) $data->project->getKey());
        }

        return DB::transaction(function () use ($parent, $data): Task {
            $subtask = ($this->createTask)($data->withParent($parent));

            $this->activity->record($parent, 'subtask_created', $data->actor, [
                'subtask_id' => (int) $subtask->getKey(),
                'title' => $subtask->title,
            ]);

            return $subtask;
        });
    }
}
