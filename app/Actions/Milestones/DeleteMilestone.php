<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Events\Milestones\MilestoneDeleted;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft deletes a milestone and detaches the tasks that pointed at it.
 *
 * Detaching is not optional. `tasks.milestone_id` has no notion of a trashed target, so a
 * task left pointing at a soft-deleted milestone would keep appearing under it in every join
 * that does not think to exclude trashed rows — and would block a timeline that groups by
 * milestone from ever showing the task again.
 *
 * The tasks themselves are untouched: a milestone is a grouping, not an owner.
 */
final class DeleteMilestone
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Milestone $milestone, ?User $actor = null): Milestone
    {
        if ($milestone->trashed()) {
            return $milestone;
        }

        $detached = DB::transaction(function () use ($milestone, $actor): int {
            $detached = Task::query()
                ->withoutWorkspaceScope()
                ->where('milestone_id', $milestone->getKey())
                ->update(['milestone_id' => null]);

            // Logged before the delete, while the subject still resolves.
            $this->activity->forUser($actor)->log($milestone, 'deleted', [
                'name' => $milestone->name,
                'tasks_detached' => $detached,
            ]);

            $milestone->delete();

            return $detached;
        });

        event(new MilestoneDeleted($milestone, $detached, $actor));

        return $milestone;
    }
}
