<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Actions\Workspaces\StatusDefinition;
use App\Actions\Workspaces\WorkspaceDefaults;
use App\Models\Project;
use App\Models\TaskStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives a project its board columns.
 *
 * Task statuses are per-project rows (ARCHITECTURE.md §5.3) so that renaming or reordering
 * one project's board never disturbs another. They are copied from the workspace template at
 * creation and diverge freely from that point on.
 *
 * Calling this twice is a no-op: a project that already has columns keeps them, because
 * re-seeding would either duplicate every column or orphan the tasks sitting in the old ones.
 */
final class SeedTaskStatuses
{
    /**
     * @param list<StatusDefinition>|null $definitions null falls back to the workspace's
     *                                                 saved board template
     * @return Collection<int, TaskStatus>
     */
    public function __invoke(Project $project, ?array $definitions = null): Collection
    {
        $existing = TaskStatus::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->ordered()
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        $resolved = $definitions === null || $definitions === []
            ? WorkspaceDefaults::taskStatuses($project->workspace)
            : StatusDefinition::withExactlyOneDefault(array_values($definitions));

        if ($resolved === []) {
            // Never leave a project without somewhere to put a task, whatever the template
            // said. The shipped defaults are the last line before an unusable board.
            $resolved = StatusDefinition::listFrom((array) config('planvio.defaults.task_statuses', []));
        }

        $workspaceId = (int) $project->workspace_id;
        $projectId = (int) $project->getKey();

        return DB::transaction(function () use ($resolved, $workspaceId, $projectId): Collection {
            /** @var Collection<int, TaskStatus> $created */
            $created = new Collection;

            foreach ($resolved as $position => $definition) {
                $created->push(TaskStatus::query()->create(
                    $definition->withPosition($position)->toTaskStatusAttributes($workspaceId, $projectId),
                ));
            }

            return $created;
        });
    }
}
