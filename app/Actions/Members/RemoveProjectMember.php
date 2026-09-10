<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Events\Members\ProjectMemberRemoved;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws explicit project membership.
 *
 * This does not always remove access: a workspace member above guest can still see every
 * project in the workspace. What it always removes is the project-scoped elevation — the
 * manager role that the capability matrix marks `+`.
 *
 * For a workspace guest it does remove access entirely, and that is the one case where their
 * open assignments have to be dealt with: a guest who can no longer open the project cannot
 * do the task either. Their open tasks are handed to `$reassignTo`, or unassigned.
 *
 * Calling it for somebody who is not a member returns false rather than failing, so a
 * double-clicked button is harmless.
 */
final class RemoveProjectMember
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function __invoke(
        Project $project,
        User $user,
        ?User $reassignTo = null,
        ?User $actor = null,
    ): bool {
        $member = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($member === null) {
            return false;
        }

        $previousRole = $member->role ?? ProjectRole::Member;
        $losesAccess = $this->losesAccess($project, $user);

        DB::transaction(function () use ($project, $user, $member, $previousRole, $losesAccess, $reassignTo, $actor): void {
            $reassigned = $losesAccess ? $this->handOverOpenTasks($project, $user, $reassignTo) : 0;

            $member->delete();

            $this->activity->forUser($actor)->log($project, 'member_removed', [
                'user_id' => (int) $user->getKey(),
                'role' => $previousRole,
                'lost_access' => $losesAccess,
                'tasks_reassigned' => $reassigned,
                'reassigned_to' => $reassignTo?->getKey(),
            ]);
        });

        $user->flushRoleCache();

        event(new ProjectMemberRemoved($project, $user, $previousRole, $actor));

        return true;
    }

    /**
     * True when project membership was the only thing making this project visible to them.
     */
    private function losesAccess(Project $project, User $user): bool
    {
        $workspaceRole = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $user->getKey())
            ->value('role');

        return $workspaceRole === null || $workspaceRole === WorkspaceRole::Guest->value;
    }

    private function handOverOpenTasks(Project $project, User $user, ?User $reassignTo): int
    {
        return Task::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->where('assignee_id', $user->getKey())
            ->whereHas('status', fn (Builder $status): Builder => $status->where('is_completed', false))
            ->update(['assignee_id' => $reassignTo?->getKey()]);
    }
}
