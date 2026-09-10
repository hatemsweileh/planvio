<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ProjectRestored;
use App\Exceptions\DuplicateProjectKey;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Brings a project back: un-archives it, and un-deletes it when it was soft deleted.
 *
 * Both states are handled by one action because to a person they are one idea — "put it
 * back" — and a project can be in both at once, having been archived before it was deleted.
 *
 * A restore can fail on the key: `unique(workspace_id, key)` covers trashed rows, so the key
 * is still reserved and cannot have been taken. The slug is checked all the same, because a
 * project deleted before the unique index existed, or restored from a database copy, may
 * collide — and a collision here surfaces as a domain error rather than as a driver
 * exception halfway through the transaction.
 */
final class RestoreProject
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly ProjectSlugGenerator $slugs,
    ) {}

    /**
     * @throws DuplicateProjectKey
     */
    public function __invoke(Project $project, ?User $actor = null): Project
    {
        if (! $project->trashed() && ! $project->is_archived) {
            return $project;
        }

        $wasTrashed = $project->trashed();
        $wasArchived = (bool) $project->is_archived;

        DB::transaction(function () use ($project, $actor, $wasTrashed, $wasArchived): void {
            if ($wasTrashed) {
                $project->restore();

                $freeSlug = ($this->slugs)(
                    $project->workspace,
                    (string) $project->name,
                    (string) $project->slug,
                    (int) $project->getKey(),
                );

                if ($freeSlug !== $project->slug) {
                    $project->slug = $freeSlug;
                }
            }

            $project->is_archived = false;
            $project->archived_at = null;
            $project->save();

            $this->activity->forUser($actor)->log($project, 'restored', [
                'was_deleted' => $wasTrashed,
                'was_archived' => $wasArchived,
            ]);
        });

        event(new ProjectRestored($project, $actor));

        return $project;
    }
}
