<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\WorkspaceRole;
use App\Events\Members\MemberRemoved;
use App\Exceptions\CannotRemoveLastOwner;
use App\Exceptions\NotAMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Takes a person out of a workspace and decides what happens to the work they were holding.
 *
 * Their open tasks must not be left pointing at somebody who can no longer see them: an
 * assignee outside the workspace is invisible in every board filter and silently drops out of
 * every workload report. `$reassignTo` hands them to a named member; without it they are
 * unassigned, which at least surfaces them as unowned work.
 *
 * Completed tasks keep their assignee. They are history — rewriting who finished them to tidy
 * up a departure would falsify the record.
 *
 * Everything the person authored stays: comments, time entries and activity rows carry a
 * nullable user reference precisely so a project's history survives its people.
 */
final class RemoveMember
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WorkspaceOwnership $ownership,
    ) {}

    /**
     * Returns the membership that was removed, or null when the person was not a member —
     * which makes calling it twice safe.
     *
     * @throws CannotRemoveLastOwner
     * @throws NotAMember when $reassignTo is not a member of the workspace
     */
    public function __invoke(
        Workspace $workspace,
        User $member,
        ?User $reassignTo = null,
        ?User $actor = null,
    ): ?WorkspaceMember {
        $membership = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $member->getKey())
            ->first();

        if ($membership === null) {
            return null;
        }

        if ($this->ownership->isLastOwner($workspace, (int) $member->getKey())) {
            throw CannotRemoveLastOwner::forWorkspace($workspace, (int) $member->getKey());
        }

        if ($reassignTo !== null) {
            $this->assertMember($workspace, $reassignTo);
        }

        $previousRole = $membership->role ?? WorkspaceRole::Member;

        $reassigned = DB::transaction(function () use (
            $workspace,
            $member,
            $membership,
            $reassignTo,
            $actor,
            $previousRole,
        ): int {
            $reassigned = $this->handOverOpenTasks($workspace, $member, $reassignTo);

            $this->detachFromProjects($workspace, $member);
            $this->detachFromTeams($workspace, $member);

            // Logged before the row goes, while the subject still resolves.
            $this->activity->forUser($actor)->log($membership, 'member_removed', [
                'user_id' => (int) $member->getKey(),
                'role' => $previousRole,
                'tasks_reassigned' => $reassigned,
                'reassigned_to' => $reassignTo?->getKey(),
            ]);

            $membership->delete();

            $this->ownership->transferAwayFrom($workspace, (int) $member->getKey());

            return $reassigned;
        });

        $member->flushRoleCache();
        $reassignTo?->flushRoleCache();

        event(new MemberRemoved($workspace, $member, $previousRole, $reassigned, $reassignTo, $actor));

        return $membership;
    }

    /**
     * @return int the number of open tasks moved
     */
    private function handOverOpenTasks(Workspace $workspace, User $member, ?User $reassignTo): int
    {
        return Task::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('assignee_id', $member->getKey())
            ->whereHas('status', fn (Builder $status): Builder => $status->where('is_completed', false))
            ->update(['assignee_id' => $reassignTo?->getKey()]);
    }

    private function detachFromProjects(Workspace $workspace, User $member): void
    {
        $projectIds = Project::query()
            ->withoutWorkspaceScope()
            ->withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->pluck('id');

        if ($projectIds->isEmpty()) {
            return;
        }

        ProjectMember::query()
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $member->getKey())
            ->delete();
    }

    private function detachFromTeams(Workspace $workspace, User $member): void
    {
        $teamIds = Team::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->pluck('id');

        if ($teamIds->isEmpty()) {
            return;
        }

        TeamMember::query()
            ->whereIn('team_id', $teamIds)
            ->where('user_id', $member->getKey())
            ->delete();
    }

    private function assertMember(Workspace $workspace, User $user): void
    {
        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $isMember) {
            throw NotAMember::ofWorkspace($workspace, (int) $user->getKey());
        }
    }
}
