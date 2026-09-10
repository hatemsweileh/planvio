<?php

declare(strict_types=1);

namespace App\Actions\Members;

use App\Enums\ProjectRole;
use App\Events\Members\ProjectMemberRoleChanged;
use App\Exceptions\NotAMember;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Moves an existing project member between project roles.
 *
 * Distinct from {@see AddProjectMember} on purpose: this one refuses to create the row. A UI
 * that changes a dropdown on a person who is no longer a member should say so rather than
 * silently re-adding them, because the row may have been removed deliberately a moment ago.
 *
 * The manager role is what the capability matrix marks `+` — `project.update`,
 * `project.archive`, `milestone.manage`, `task.delete` and the rest are granted to workspace
 * managers only inside projects where they hold it — so this is a privilege change, and it is
 * recorded as one.
 */
final class ChangeProjectMemberRole
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @throws NotAMember
     */
    public function __invoke(
        Project $project,
        User $user,
        ProjectRole $role,
        ?User $actor = null,
    ): ProjectMember {
        $member = ProjectMember::query()
            ->where('project_id', $project->getKey())
            ->where('user_id', $user->getKey())
            ->first();

        if ($member === null) {
            throw NotAMember::ofProject($project, (int) $user->getKey());
        }

        $previous = $member->role ?? ProjectRole::Member;

        if ($previous === $role) {
            return $member;
        }

        DB::transaction(function () use ($project, $member, $user, $role, $previous, $actor): void {
            $member->role = $role;
            $member->save();

            $this->activity->forUser($actor)->log($project, 'member_role_changed', [
                'user_id' => (int) $user->getKey(),
                'changes' => [
                    'role' => ['old' => $previous->value, 'new' => $role->value],
                ],
            ]);
        });

        $user->flushRoleCache();

        event(new ProjectMemberRoleChanged($member, $project, $previous, $role, $actor));

        return $member;
    }
}
