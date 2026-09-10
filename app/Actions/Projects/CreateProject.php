<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Workspaces\StatusDefinition;
use App\Enums\Priority;
use App\Enums\ProjectHealth;
use App\Enums\ProjectRole;
use App\Enums\ProjectType;
use App\Events\Projects\ProjectCreated;
use App\Exceptions\DomainException;
use App\Exceptions\NotAMember;
use App\Exceptions\WorkspaceMismatch;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Creates a project, its board and its first member in one transaction.
 *
 * All three are required for the project to be usable: without board columns a task has
 * nowhere to live, and without the owner's project membership the owner may be unable to
 * reach the project they just made — a workspace guest sees only projects they are an
 * explicit member of, and the capability matrix grants a manager `project.update` only
 * inside projects where they hold `ProjectRole::manager`.
 */
final class CreateProject
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly ProjectKeyGenerator $keys,
        private readonly ProjectSlugGenerator $slugs,
        private readonly SeedTaskStatuses $seedTaskStatuses,
    ) {}

    /**
     * @param list<StatusDefinition>|null $statusTemplate board columns to seed instead of
     *                                                    the workspace's own template
     */
    public function __invoke(
        Workspace $workspace,
        User $owner,
        ProjectAttributes $attributes,
        ?array $statusTemplate = null,
    ): Project {
        $name = trim((string) $attributes->name);

        if ($name === '') {
            throw new DomainException(__('A project needs a name.'));
        }

        $this->assertMember($workspace, (int) $owner->getKey());

        $managerId = $attributes->managerId;

        if ($managerId !== null) {
            $this->assertMember($workspace, $managerId);
        }

        $statusId = $this->resolveStatusId($workspace, $attributes->statusId);
        $key = ($this->keys)($workspace, $name, $attributes->key);
        $slug = ($this->slugs)($workspace, $name, $attributes->slug);

        $project = DB::transaction(function () use (
            $workspace,
            $owner,
            $attributes,
            $name,
            $key,
            $slug,
            $statusId,
            $managerId,
            $statusTemplate,
        ): Project {
            $project = new Project;
            $project->fill($attributes->toColumns());

            $project->workspace_id = $workspace->getKey();
            $project->name = $name;
            $project->key = $key;
            $project->slug = $slug;
            $project->owner_id = $owner->getKey();
            $project->manager_id = $managerId;
            $project->status_id = $statusId;
            $project->type ??= ProjectType::General;
            $project->priority ??= Priority::Medium;
            $project->health ??= ProjectHealth::OnTrack;
            $project->color ??= (string) config('planvio.brand.primary_color', '#3F66B0');
            $project->currency ??= $workspace->currency;
            $project->progress = 0;
            $project->is_archived = false;
            $project->settings = $attributes->settings;
            $project->ai_settings = $attributes->aiSettings;
            $project->save();

            ($this->seedTaskStatuses)($project, $statusTemplate);

            $this->addManager($project, (int) $owner->getKey());

            if ($managerId !== null && $managerId !== (int) $owner->getKey()) {
                $this->addManager($project, $managerId);
            }

            $this->activity->forUser($owner)->log($project, 'created', [
                'name' => $project->name,
                'key' => $project->key,
                'type' => $project->type,
            ]);

            return $project;
        });

        $owner->flushRoleCache();

        event(new ProjectCreated($project, $owner));

        return $project;
    }

    /**
     * A project must land on a lifecycle stage that belongs to its own workspace: statuses
     * are workspace-level rows and `projects.status_id` has no tenant column of its own.
     */
    private function resolveStatusId(Workspace $workspace, ?int $statusId): ?int
    {
        if ($statusId !== null) {
            $status = ProjectStatus::query()
                ->withoutWorkspaceScope()
                ->whereKey($statusId)
                ->first();

            if ($status === null || (int) $status->workspace_id !== (int) $workspace->getKey()) {
                throw WorkspaceMismatch::between(
                    'project_status',
                    $status === null ? 0 : (int) $status->workspace_id,
                    'workspace',
                    (int) $workspace->getKey(),
                );
            }

            return $statusId;
        }

        $default = ProjectStatus::query()
            ->withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->orderByDesc('is_default')
            ->ordered()
            ->first();

        return $default?->getKey();
    }

    private function assertMember(Workspace $workspace, int $userId): void
    {
        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            throw NotAMember::ofWorkspace($workspace, $userId);
        }
    }

    private function addManager(Project $project, int $userId): void
    {
        ProjectMember::query()->firstOrCreate(
            [
                'project_id' => $project->getKey(),
                'user_id' => $userId,
            ],
            ['role' => ProjectRole::Manager],
        );
    }
}
