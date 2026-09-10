<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ProjectDeleted;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Soft deletes a project.
 *
 * Its tasks, milestones, statuses and time entries are left alone: none of them cascade on a
 * soft delete, so they come back untouched if the project is restored. They are unreachable
 * in the meantime because every route to them goes through the project.
 *
 * The project is archived on the way out. That way a code path that reads a trashed project
 * — a report joining on `withTrashed()`, an AI context builder — still sees a project that
 * is plainly not active.
 */
final class DeleteProject
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Project $project, ?User $actor = null): Project
    {
        if ($project->trashed()) {
            return $project;
        }

        DB::transaction(function () use ($project, $actor): void {
            // Logged first: an activity row written after the delete would describe a
            // subject the default query no longer resolves.
            $this->activity->forUser($actor)->log($project, 'deleted', [
                'name' => $project->name,
                'key' => $project->key,
            ]);

            if (! $project->is_archived) {
                $project->is_archived = true;
                $project->archived_at = now();
            }

            $project->save();
            $project->delete();
        });

        event(new ProjectDeleted($project, $actor));

        return $project;
    }
}
