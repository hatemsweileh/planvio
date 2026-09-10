<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\ProjectRole;
use App\Events\Members\ProjectMemberAdded;
use App\Exceptions\NotAMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Gives somebody explicit membership of a project.
 *
 * Workspace membership is a precondition, not a nicety: `project_members` has no tenant
 * column of its own, so a row for a user outside the workspace would be a cross-tenant grant
 * that no policy is looking for.
 *
 * Adding an existing member is not an error — it re-roles them, which is what the person
 * clicking "add" almost always meant when the row was already there.
 */
final class AddProjectMember
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @throws NotAMember when the user does not belong to the project's workspace
     */
    public function __invoke(
        Project $project,
        User $user,
        ProjectRole $role = ProjectRole::Member,
        ?User $actor = null,
    ): ProjectMember {
        $this->assertWorkspaceMember($project, $user);

        $existing = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($existing !== null && $existing->role === $role) {
            return $existing;
        }

        $member = DB::transaction(function () use ($project, $user, $role, $actor, $existing): ProjectMember {
            $member = $existing ?? new ProjectMember([
                'project_id' => $project->getKey(),
                'user_id' => $user->getKey(),
            ]);

            $previous = $member->role;
            $member->role = $role;
            $member->save();

            $this->activity->forUser($actor)->log($project, 'member_added', [
                'user_id' => (int) $user->getKey(),
                'role' => $role,
                'previous_role' => $previous,
            ]);

            return $member;
        });

        $user->flushRoleCache();

        event(new ProjectMemberAdded($member, $project, $actor));

        return $member;
    }

    private function assertWorkspaceMember(Project $project, User $user): void
    {
        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $user->getKey())
            ->exists();

        if (! $isMember) {
            throw NotAMember::ofWorkspace($project->workspace, (int) $user->getKey());
        }
    }
}
