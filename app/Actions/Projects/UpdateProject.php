<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ProjectUpdated;
use App\Exceptions\DomainException;
use App\Exceptions\NotAMember;
use App\Exceptions\WorkspaceMismatch;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Applies a partial change to a project.
 *
 * Two rules are worth stating because they are easy to get wrong the other way round:
 *
 * - The key and the slug move only when asked. Renaming "Website" to "Marketing Site" must
 *   not silently turn WEB-42 into MS-42 — the old key is in commit messages and chat logs.
 * - Setting `health` explicitly latches `health_set_manually`, which is what stops the
 *   automatic health calculation from overwriting a judgement somebody made on purpose.
 */
final class UpdateProject
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly ProjectKeyGenerator $keys,
        private readonly ProjectSlugGenerator $slugs,
    ) {}

    public function __invoke(
        Project $project,
        ProjectAttributes $attributes,
        ?User $actor = null,
    ): Project {
        $columns = $attributes->toColumns();

        if (array_key_exists('name', $columns) && trim((string) $columns['name']) === '') {
            throw new DomainException(__('A project needs a name.'));
        }

        $workspace = $project->workspace;

        foreach (['owner_id', 'manager_id'] as $column) {
            if (isset($columns[$column])) {
                $this->assertMember($project, (int) $columns[$column]);
            }
        }

        if (array_key_exists('status_id', $columns) && $columns['status_id'] !== null) {
            $this->assertStatusInWorkspace($project, (int) $columns['status_id']);
        }

        if ($attributes->key !== null && trim($attributes->key) !== '') {
            $columns['key'] = ($this->keys)(
                $workspace,
                (string) ($columns['name'] ?? $project->name),
                $attributes->key,
                (int) $project->getKey(),
            );
        }

        if ($attributes->slug !== null && trim($attributes->slug) !== '') {
            $columns['slug'] = ($this->slugs)(
                $workspace,
                (string) ($columns['name'] ?? $project->name),
                $attributes->slug,
                (int) $project->getKey(),
            );
        }

        $project->fill($columns);

        if ($attributes->health !== null) {
            $project->health_set_manually = true;
        }

        if ($attributes->hasSettings()) {
            $current = is_array($project->settings) ? $project->settings : [];
            $project->settings = array_replace($current, $attributes->settings ?? []);
        }

        if ($attributes->hasAiSettings()) {
            $current = is_array($project->ai_settings) ? $project->ai_settings : [];
            $project->ai_settings = array_replace($current, $attributes->aiSettings ?? []);
        }

        if (! $project->isDirty()) {
            return $project;
        }

        $changes = ActivityLogger::changes($project, $this->diffableAttributes($project));
        $properties = $changes === [] ? [] : ['changes' => $changes];

        DB::transaction(function () use ($project, $actor, $properties): void {
            $project->save();

            $this->activity->forUser($actor)->log($project, 'updated', $properties);
        });

        event(new ProjectUpdated($project, $changes, $actor));

        return $project;
    }

    /**
     * The JSON documents are merged rather than replaced, so recording both sides of them
     * would fill the feed with the parts that did not change.
     *
     * @return list<string>
     */
    private function diffableAttributes(Project $project): array
    {
        return array_values(array_diff(
            array_keys($project->getDirty()),
            ['settings', 'ai_settings'],
        ));
    }

    private function assertMember(Project $project, int $userId): void
    {
        $isMember = WorkspaceMember::withoutWorkspaceScope()
            ->where('workspace_id', $project->workspace_id)
            ->where('user_id', $userId)
            ->exists();

        if (! $isMember) {
            throw NotAMember::ofWorkspace($project->workspace, $userId);
        }
    }

    private function assertStatusInWorkspace(Project $project, int $statusId): void
    {
        $status = ProjectStatus::query()
            ->withoutWorkspaceScope()
            ->whereKey($statusId)
            ->first();

        if ($status === null || (int) $status->workspace_id !== (int) $project->workspace_id) {
            throw WorkspaceMismatch::between(
                'project_status',
                $status === null ? 0 : (int) $status->workspace_id,
                'project',
                (int) $project->workspace_id,
            );
        }
    }
}
