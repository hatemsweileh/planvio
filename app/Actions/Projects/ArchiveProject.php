<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ProjectArchived;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Takes a project out of the active set without deleting anything.
 *
 * Archiving is the reversible half of retiring a project: the rows stay queryable, the
 * project keeps its key, and `scopeActive()` stops returning it. An already-archived project
 * is left exactly as it was — re-archiving must not move `archived_at`, which is the only
 * record of when it actually happened.
 */
final class ArchiveProject
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(Project $project, ?User $actor = null): Project
    {
        if ($project->is_archived) {
            return $project;
        }

        DB::transaction(function () use ($project, $actor): void {
            $project->is_archived = true;
            $project->archived_at = now();
            $project->save();

            $this->activity->forUser($actor)->log($project, 'archived', [
                'archived_at' => $project->archived_at,
            ]);
        });

        event(new ProjectArchived($project, $actor));

        return $project;
    }
}
