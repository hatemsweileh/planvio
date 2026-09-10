<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Actions\Members\AddProjectMember;
use App\Actions\Members\ChangeProjectMemberRole;
use App\Actions\Members\RemoveProjectMember;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\Arguments;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Enums\ProjectRole;
use App\Enums\WorkspaceRole;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * `manage_project_member` — add somebody to a project, change their project role, or take them
 * off it.
 *
 * High risk because project membership is where the capability matrix's `+` refinements live.
 * Making somebody a project `manager` grants them `project.update`, `project.archive`,
 * `task.delete`, `milestone.manage` and `time.view_all` *inside that project*, and removing a
 * workspace guest from a project removes their access to it entirely. Both are privilege
 * changes, and an agent should not be able to make one quietly.
 *
 * The three operations map onto three separate Actions with different invariants, and the
 * tool does not paper over the differences: `change_role` refuses to create a missing
 * membership row — somebody may have removed it a moment ago on purpose — and `remove` is the
 * only operation that accepts a reassignee, because it is the only one that can strand open
 * work.
 *
 * Not on the unwaivable approval list, but at high risk it exceeds every mode's auto-execute
 * ceiling in `config('ai.approvals.auto_execute_max_risk')`, so in practice the runner still
 * asks a human.
 */
final class ManageProjectMemberTool extends ElevatedTool
{
    public function __construct(
        private readonly AddProjectMember $addMember,
        private readonly RemoveProjectMember $removeMember,
        private readonly ChangeProjectMemberRole $changeRole,
    ) {}

    public function name(): string
    {
        return 'manage_project_member';
    }

    public function group(): string
    {
        return 'members';
    }

    public function description(): string
    {
        return 'Add a workspace member to a project, change their project role, or remove them '
            .'from it. The project "manager" role grants permission to update and archive that '
            .'project and to delete its tasks, so use it deliberately. Removing a workspace '
            .'guest removes their access to the project and hands their open tasks to '
            .'reassign_to_user_id or leaves them unassigned.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['project_id', 'user_id', 'operation'],
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The project whose membership changes.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The workspace member to add, re-role or remove.',
                ],
                'operation' => [
                    'type' => 'string',
                    'enum' => ['add', 'remove', 'change_role'],
                    'description' => '"add" grants membership, "change_role" moves an existing member between roles, "remove" withdraws membership.',
                ],
                'role' => [
                    'type' => 'string',
                    'enum' => ['manager', 'member', 'guest'],
                    'description' => 'Required for "change_role", optional for "add" (defaults to "member"), not accepted for "remove".',
                ],
                'reassign_to_user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Only for "remove". A workspace member who inherits their open tasks in this project if the removal costs them access.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::High;
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectManageMembers;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle(
            $args,
            $ctx,
            fn (Arguments $in, AgentContext $ctx): ToolResult => $this->manage($in, $ctx),
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array
    {
        $projectId = $args['project_id'] ?? null;
        $userId = $args['user_id'] ?? null;

        $project = $this->resolveProject(is_numeric($projectId) ? (int) $projectId : 0, $ctx);
        $member = $this->resolveUser(is_numeric($userId) ? (int) $userId : 0, $ctx);

        if (! $project instanceof Project || ! $member instanceof User) {
            return [];
        }

        $operation = is_string($args['operation'] ?? null) ? $args['operation'] : '';
        $role = is_string($args['role'] ?? null) ? ProjectRole::tryFrom($args['role']) : null;

        return $this->countsFor($project, $member, $operation, $this->roleFor($operation, $role), $ctx);
    }

    /* ------------------------------------------------------------------ *
     * The call
     * ------------------------------------------------------------------ */

    private function manage(Arguments $in, AgentContext $ctx): ToolResult
    {
        $operation = $in->string('operation');
        $shape = $this->checkShape($operation, $in);

        if ($shape instanceof ToolResult) {
            return $shape;
        }

        $projectId = $in->int('project_id');
        $project = $this->resolveProject($projectId, $ctx);

        if (! $project instanceof Project) {
            return $this->notFound(__('project'), $projectId);
        }

        $ctx->assertInWorkspace($project);

        $userId = $in->int('user_id');
        $member = $this->resolveUser($userId, $ctx);

        if (! $member instanceof User) {
            return $this->notFound(__('workspace member'), $userId);
        }

        if ($ctx->cannot(Permission::ProjectManageMembers, $project)) {
            return $this->denied($ctx, __('manage members of :name', ['name' => $project->name]));
        }

        $reassignTo = null;

        if ($in->has('reassign_to_user_id')) {
            $reassignToId = $in->int('reassign_to_user_id');

            if ($reassignToId === $userId) {
                return ToolResult::failed(
                    __('Open tasks cannot be reassigned to the person being removed.'),
                    'invalid_arguments',
                );
            }

            $reassignTo = $this->resolveUser($reassignToId, $ctx);

            if (! $reassignTo instanceof User) {
                return $this->notFound(__('workspace member'), $reassignToId);
            }
        }

        $role = $this->roleFor($operation, $in->enum('role', ProjectRole::class));
        $facts = $this->countsFor($project, $member, $operation, $role, $ctx);

        return match ($operation) {
            'add' => $this->applyAdd($project, $member, $role ?? ProjectRole::Member, $facts, $ctx),
            'change_role' => $this->applyChangeRole($project, $member, $role ?? ProjectRole::Member, $facts, $ctx),
            'remove' => $this->applyRemove($project, $member, $reassignTo, $facts, $ctx),
            default => ToolResult::failed(
                __('Unknown operation :operation.', ['operation' => $operation]),
                'invalid_arguments',
            ),
        };
    }

    /**
     * Arguments the schema allows but this operation does not.
     *
     * A JSON Schema cannot say "role is required for change_role and forbidden for remove"
     * in a way a model reliably honours, so it is said here — and said as a rejection, not as
     * a silently dropped field.
     */
    private function checkShape(string $operation, Arguments $in): ?ToolResult
    {
        if ($operation === 'change_role' && ! $in->has('role')) {
            return ToolResult::failed(
                __('role is required when changing somebody\'s project role.'),
                'invalid_arguments',
            );
        }

        if ($operation === 'remove' && $in->has('role')) {
            return ToolResult::failed(
                __('role is not accepted when removing somebody from a project.'),
                'invalid_arguments',
            );
        }

        if ($operation !== 'remove' && $in->has('reassign_to_user_id')) {
            return ToolResult::failed(
                __('reassign_to_user_id is only accepted when removing somebody from a project.'),
                'invalid_arguments',
            );
        }

        return null;
    }

    private function roleFor(string $operation, ?ProjectRole $given): ?ProjectRole
    {
        if ($operation === 'remove') {
            return null;
        }

        return $given ?? ($operation === 'add' ? ProjectRole::Member : null);
    }

    /* ------------------------------------------------------------------ *
     * The three Actions
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, scalar|null|array<array-key, mixed>> $facts
     */
    private function applyAdd(
        Project $project,
        User $member,
        ProjectRole $role,
        array $facts,
        AgentContext $ctx,
    ): ToolResult {
        $membership = ($this->addMember)($project, $member, $role, $ctx->user);

        return ToolResult::ok(
            __('Added :name to :key as :role.', [
                'name' => self::clip($member->name, 80),
                'key' => $project->key,
                'role' => $role->value,
            ]),
            $facts,
            $membership,
        );
    }

    /**
     * @param array<string, scalar|null|array<array-key, mixed>> $facts
     */
    private function applyChangeRole(
        Project $project,
        User $member,
        ProjectRole $role,
        array $facts,
        AgentContext $ctx,
    ): ToolResult {
        $membership = ($this->changeRole)($project, $member, $role, $ctx->user);

        return ToolResult::ok(
            __(':name is now :role on :key.', [
                'name' => self::clip($member->name, 80),
                'role' => $role->value,
                'key' => $project->key,
            ]),
            $facts,
            $membership,
        );
    }

    /**
     * @param array<string, scalar|null|array<array-key, mixed>> $facts
     */
    private function applyRemove(
        Project $project,
        User $member,
        ?User $reassignTo,
        array $facts,
        AgentContext $ctx,
    ): ToolResult {
        $removed = ($this->removeMember)($project, $member, $reassignTo, $ctx->user);

        if (! $removed) {
            return ToolResult::skipped(
                __(':name is not a member of :key. Nothing was changed.', [
                    'name' => self::clip($member->name, 80),
                    'key' => $project->key,
                ]),
                $facts,
            );
        }

        return ToolResult::ok(
            $facts['loses_access'] === true
                ? __('Removed :name from :key. They can no longer see it, and :tasks open tasks were handed over.', [
                    'name' => self::clip($member->name, 80),
                    'key' => $project->key,
                    'tasks' => $facts['open_tasks'],
                ])
                : __('Removed :name from :key. They keep workspace-level access to it and their tasks are untouched.', [
                    'name' => self::clip($member->name, 80),
                    'key' => $project->key,
                ]),
            $facts,
            $project,
        );
    }

    /* ------------------------------------------------------------------ *
     * Consequences
     * ------------------------------------------------------------------ */

    /**
     * The privilege change, and what a removal would move.
     *
     * Three aggregates plus the two role reads the card needs. `loses_access` mirrors the rule
     * inside `RemoveProjectMember`: only a workspace guest, or somebody with no workspace role
     * at all, actually loses sight of the project — for everybody else the removal withdraws
     * an elevation rather than access.
     *
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    private function countsFor(
        Project $project,
        User $member,
        string $operation,
        ?ProjectRole $role,
        AgentContext $ctx,
    ): array {
        $projectId = (int) $project->getKey();
        $memberId = (int) $member->getKey();
        $workspaceRole = $this->resolveMembership($memberId, $ctx)?->role;
        $losesAccess = $operation === 'remove'
            && ($workspaceRole === null || $workspaceRole === WorkspaceRole::Guest);

        return $ctx->bindWorkspace(function () use (
            $project,
            $projectId,
            $member,
            $memberId,
            $operation,
            $role,
            $workspaceRole,
            $losesAccess,
        ): array {
            $current = $this->resolveProjectMembership($project, $member);

            return [
                'project' => (string) $project->name,
                'key' => (string) $project->key,
                'member' => (string) $member->name,
                'workspace_role' => $workspaceRole?->value,
                'operation' => $operation,
                'current_role' => $current?->role?->value,
                'new_role' => $operation === 'remove' ? null : $role?->value,
                'loses_access' => $losesAccess,
                'open_tasks' => Task::query()
                    ->where('project_id', $projectId)
                    ->where('assignee_id', $memberId)
                    ->whereHas('status', static fn (Builder $status): Builder => $status->where('is_completed', false))
                    ->count(),
                'project_members' => ProjectMember::query()->where('project_id', $projectId)->count(),
            ];
        });
    }
}
