<?php

declare(strict_types=1);

namespace App\Ai\Tools\Elevated;

use App\Actions\Members\RemoveMember;
use App\Actions\Members\WorkspaceOwnership;
use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Ai\Tools\Concerns\Arguments;
use App\Enums\AiToolRisk;
use App\Enums\Permission;
use App\Models\Comment;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;

/**
 * `remove_workspace_member` — take a person out of the workspace.
 *
 * The only tool whose subject is a person, and the one whose consequences are least visible
 * from the request. Removing somebody detaches them from every project and team and hands
 * their open tasks to `reassign_to_user_id`, or leaves those tasks unassigned. What they
 * authored — comments, time entries, activity — stays, because a project's history has to
 * survive its people.
 *
 * On the unwaivable approval list. No mode and no `AiPolicy` executes it without a person
 * (AI_SECURITY.md, "Approval gating"), enforced by the runner's gate and again by
 * {@see ElevatedTool::approvalGate()} here.
 *
 * Neither user id is ever taken at face value: both the member and the person inheriting
 * their work are resolved through `workspace_members`, so an id belonging to another
 * workspace is not found rather than acted on.
 */
final class RemoveWorkspaceMemberTool extends ElevatedTool
{
    public function __construct(
        private readonly RemoveMember $removeMember,
        private readonly WorkspaceOwnership $ownership,
    ) {}

    public function name(): string
    {
        return 'remove_workspace_member';
    }

    public function group(): string
    {
        return 'members';
    }

    public function description(): string
    {
        return 'Remove one person from this workspace. They lose access to every project and '
            .'team in it, and their open tasks go to reassign_to_user_id or are left '
            .'unassigned; their comments, time entries and history stay. Always requires a '
            .'human approval, in every mode and under every policy. The last owner of a '
            .'workspace cannot be removed.';
    }

    /**
     * @return array<string, mixed>
     */
    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['user_id'],
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The workspace member to remove. One person per call.',
                ],
                'reassign_to_user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional. Another member of this workspace who inherits their open tasks. Omitted, those tasks are left unassigned.',
                ],
            ],
        ];
    }

    public function risk(): AiToolRisk
    {
        return AiToolRisk::Destructive;
    }

    public function permission(): ?Permission
    {
        return Permission::UsersManage;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function execute(array $args, AgentContext $ctx): ToolResult
    {
        return $this->handle(
            $args,
            $ctx,
            fn (Arguments $in, AgentContext $ctx): ToolResult => $this->remove($args, $in, $ctx),
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    public function consequences(array $args, AgentContext $ctx): array
    {
        $id = $args['user_id'] ?? null;
        $userId = is_numeric($id) ? (int) $id : 0;

        $member = $this->resolveUser($userId, $ctx);
        $membership = $this->resolveMembership($userId, $ctx);

        if (! $member instanceof User || ! $membership instanceof WorkspaceMember) {
            return [];
        }

        return $this->countsFor($member, $membership, $ctx);
    }

    /**
     * @param array<string, mixed> $args the raw call, for the approval key
     */
    private function remove(array $args, Arguments $in, AgentContext $ctx): ToolResult
    {
        $userId = $in->int('user_id');
        $member = $this->resolveUser($userId, $ctx);
        $membership = $this->resolveMembership($userId, $ctx);

        if (! $member instanceof User || ! $membership instanceof WorkspaceMember) {
            return $this->notFound(__('workspace member'), $userId);
        }

        $ctx->assertInWorkspace($membership);

        // The capability the matrix names, and the ability the policy actually guards. They
        // resolve to the same rule today; asking both means a change to either one closes
        // this door rather than leaving it ajar.
        if ($ctx->cannot(Permission::UsersManage, $membership)
            || ! $ctx->allows('delete', [WorkspaceMember::class, $membership])) {
            return $this->denied($ctx, __('remove :name from this workspace', ['name' => $member->name]));
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

        // Nothing past this line runs without a recorded human approval, whatever the mode
        // and whatever any policy says.
        $blocked = $this->approvalGate($args, $ctx, $membership);

        if ($blocked instanceof ToolResult) {
            return $blocked;
        }

        $facts = $this->countsFor($member, $membership, $ctx);

        // A broken invariant — the last owner, or a reassignee outside the workspace — throws
        // a DomainException, which the shared pipeline turns into a factual refusal.
        $removed = ($this->removeMember)($ctx->workspace, $member, $reassignTo, $ctx->user);

        if (! $removed instanceof WorkspaceMember) {
            return ToolResult::skipped(
                __(':name is not a member of this workspace. Nothing was changed.', [
                    'name' => self::clip($member->name, 80),
                ]),
                $facts,
            );
        }

        return ToolResult::ok(
            __('Removed :name from this workspace. :tasks open tasks went to :destination, and they were detached from :projects projects and :teams teams. Their comments, time entries and history are unchanged.', [
                'name' => self::clip($member->name, 80),
                'tasks' => $facts['open_tasks'],
                'destination' => $reassignTo instanceof User
                    ? (string) self::clip($reassignTo->name, 80)
                    : __('nobody and are now unassigned'),
                'projects' => $facts['projects'],
                'teams' => $facts['teams'],
            ]),
            $facts,
            $removed,
        );
    }

    /**
     * What the removal moves, and what it leaves alone.
     *
     * Six aggregates. The project and team counts use subqueries against the workspace-scoped
     * models rather than plucking id lists, so each stays one statement however many projects
     * the workspace has. `open_tasks` mirrors the Action exactly — tasks in a status not
     * marked completed — so the number on the card is the number that will move.
     *
     * @return array<string, scalar|null|array<array-key, mixed>>
     */
    private function countsFor(User $member, WorkspaceMember $membership, AgentContext $ctx): array
    {
        $memberId = (int) $member->getKey();
        $workspace = $ctx->workspace;
        $ownership = $this->ownership;

        return $ctx->bindWorkspace(static fn (): array => [
            'member' => (string) $member->name,
            'role' => $membership->role?->value,
            'is_last_owner' => $ownership->isLastOwner($workspace, $memberId),
            'open_tasks' => Task::query()
                ->where('assignee_id', $memberId)
                ->whereHas('status', static fn (Builder $status): Builder => $status->where('is_completed', false))
                ->count(),
            'projects' => ProjectMember::query()
                ->where('user_id', $memberId)
                ->whereIn('project_id', Project::query()->select('id'))
                ->count(),
            'teams' => TeamMember::query()
                ->where('user_id', $memberId)
                ->whereIn('team_id', Team::query()->select('id'))
                ->count(),
            'comments_retained' => Comment::query()->where('user_id', $memberId)->count(),
            'time_entries_retained' => TimeEntry::query()->where('user_id', $memberId)->count(),
        ]);
    }
}
