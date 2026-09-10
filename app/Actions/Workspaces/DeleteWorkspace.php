<?php

declare(strict_types=1);

namespace App\Actions\Workspaces;

use App\Events\Workspaces\WorkspaceDeleted;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityLogger;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * Soft deletes a workspace.
 *
 * Nothing below it is touched. Every tenant-scoped table carries `workspace_id` with
 * `cascadeOnDelete`, so a hard delete would erase the entire tenant in one statement — which
 * is exactly why this action never issues one. The rows stay, the workspace stops resolving,
 * and a restore brings the whole tenant back intact.
 *
 * The workspace is suspended at the same time, so the tenant is closed to its members even
 * in a code path that reaches a workspace without going through the trashed check.
 */
final class DeleteWorkspace
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    public function __invoke(Workspace $workspace, ?User $actor = null): Workspace
    {
        if ($workspace->trashed()) {
            return $workspace;
        }

        DB::transaction(function () use ($workspace, $actor): void {
            // Logged before the delete: an activity row written afterwards would describe a
            // subject that no longer resolves through the default query.
            $this->activity->forUser($actor)->log($workspace, 'deleted', [
                'name' => $workspace->name,
                'slug' => $workspace->slug,
            ]);

            $workspace->is_suspended = true;
            $workspace->save();
            $workspace->delete();
        });

        // A request that just deleted the workspace it was operating in must not carry the
        // binding into the rest of the response.
        if ($this->currentWorkspace->id() === (int) $workspace->getKey()) {
            $this->currentWorkspace->forget();
        }

        event(new WorkspaceDeleted($workspace, $actor));

        return $workspace;
    }
}
