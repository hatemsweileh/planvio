<?php

declare(strict_types=1);

namespace App\Ai\Tools\Read;

use App\Ai\Agent\AgentContext;
use App\Ai\Agent\ToolResult;
use App\Enums\Permission;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\WorkspaceMember;

/**
 * Who is on a project, and in what role.
 *
 * The list is of *explicit* memberships, which is not the same as "everyone who can see this
 * project": a workspace member above guest already reaches every project in the workspace
 * without a row here. An empty list therefore means "nobody was added", not "nobody has
 * access", and the result says so rather than letting the model infer an isolated project.
 *
 * Each member's workspace role is resolved in one extra query and returned alongside the
 * project role, because the two together are what decide what a person may actually do — a
 * project manager who is a workspace guest is not the same as one who is an admin.
 */
final class ListProjectMembersTool extends ReadTool
{
    public function name(): string
    {
        return 'list_project_members';
    }

    public function description(): string
    {
        return 'List the people explicitly added to a project, with their project role and '
            .'their workspace role. Projects with no explicit members are still visible to '
            .'every workspace member above guest.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['project'],
            'properties' => [
                'project' => [
                    'type' => ['integer', 'string'],
                    'description' => 'The project id, or its key such as WEB.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'description' => 'Maximum members to return.',
                ],
            ],
        ];
    }

    public function permission(): ?Permission
    {
        return Permission::ProjectView;
    }

    protected function read(array $args, AgentContext $ctx): ToolResult
    {
        $reference = $args['project'];
        $reference = is_int($reference) ? $reference : (string) $reference;

        $project = $this->resolveProject($ctx, $reference);

        if ($project === null) {
            return $this->notFound('project', $reference);
        }

        $ctx->assertInWorkspace($project);

        if ($ctx->cannot(Permission::ProjectView, $project)) {
            return $this->denied('project');
        }

        $limit = self::pageSize($args['limit'] ?? null, 'list', 50);
        $total = ProjectMember::query()->forProject($project)->count();

        $memberships = ProjectMember::query()
            ->forProject($project)
            ->with('user:id,name,email,job_title,is_active')
            ->orderBy('project_members.id')
            ->limit($limit)
            ->get();

        $workspaceRoles = $this->workspaceRoles($ctx, $memberships->pluck('user_id')->all());

        $rows = [];

        foreach ($memberships as $membership) {
            $user = $membership->user;

            if ($user === null) {
                continue;
            }

            $userId = (int) $user->getKey();

            $rows[] = [
                'user_id' => $userId,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'job_title' => $user->job_title,
                'project_role' => $membership->role?->value,
                'workspace_role' => $workspaceRoles[$userId] ?? null,
                'is_active' => (bool) $user->is_active,
            ];
        }

        $data = $this->page($rows, $total, 'members');
        $data['project'] = [
            'id' => (int) $project->getKey(),
            'key' => (string) $project->key,
            'name' => (string) $project->name,
        ];
        $data['owner'] = $this->personName($project, 'owner');
        $data['manager'] = $this->personName($project, 'manager');

        if ($total === 0) {
            $data['note'] = __('ai.tools.notes.no_explicit_members');
        }

        $data = $this->fit($data, 'members');

        return ToolResult::ok(
            __('ai.tools.summary.members', [
                'returned' => $data['returned'],
                'total' => $data['total'],
                'project' => $project->key.' '.$project->name,
            ]),
            $data,
            $project,
        );
    }

    /**
     * @param list<mixed> $userIds
     * @return array<int, string>
     */
    private function workspaceRoles(AgentContext $ctx, array $userIds): array
    {
        $ids = [];

        foreach ($userIds as $id) {
            $resolved = self::asId($id);

            if ($resolved !== null) {
                $ids[] = $resolved;
            }
        }

        if ($ids === []) {
            return [];
        }

        $roles = [];

        $memberships = WorkspaceMember::query()
            ->forWorkspace($ctx->workspace)
            ->whereIn('workspace_members.user_id', $ids)
            ->get(['user_id', 'role']);

        foreach ($memberships as $membership) {
            $roles[(int) $membership->user_id] = $membership->role?->value ?? '';
        }

        return $roles;
    }

    /**
     * The owner's or manager's name, read from the project's own relation rather than by
     * looking up an id the model supplied.
     */
    private function personName(Project $project, string $relation): ?string
    {
        $project->loadMissing([$relation.':id,name']);

        $user = $project->getRelation($relation);

        return $user === null ? null : (string) $user->name;
    }
}
