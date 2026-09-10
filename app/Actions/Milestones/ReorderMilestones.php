<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Events\Milestones\MilestonesReordered;
use App\Exceptions\WorkspaceMismatch;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites the order of a project's milestones.
 *
 * The caller sends the ids it knows about, in the order it wants. Anything it did not send —
 * a milestone created by somebody else while the list was open — keeps its relative place at
 * the end rather than being silently dragged to the front, which is what assigning positions
 * only to the listed ids would do.
 *
 * Ids from another project are refused outright. A drag-and-drop payload is client-supplied,
 * and quietly renumbering somebody else's milestone would be a cross-project write dressed up
 * as a reorder.
 *
 * Only rows whose position actually moves are written, so re-sending the current order costs
 * nothing and leaves no activity behind.
 */
final class ReorderMilestones
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * @param list<int> $orderedIds
     * @return int the number of milestones whose position changed
     *
     * @throws WorkspaceMismatch when an id does not belong to the project
     */
    public function __invoke(Project $project, array $orderedIds, ?User $actor = null): int
    {
        $milestones = Milestone::query()
            ->withoutWorkspaceScope()
            ->where('project_id', $project->getKey())
            ->ordered()
            ->get()
            ->keyBy(static fn (Milestone $milestone): int => (int) $milestone->getKey());

        $requested = [];

        foreach ($orderedIds as $id) {
            $id = (int) $id;

            if (! $milestones->has($id)) {
                throw WorkspaceMismatch::between(
                    'milestone',
                    $id,
                    'project',
                    (int) $project->getKey(),
                );
            }

            if (! in_array($id, $requested, true)) {
                $requested[] = $id;
            }
        }

        $remaining = $milestones->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->reject(static fn (int $id): bool => in_array($id, $requested, true))
            ->values()
            ->all();

        $final = array_merge($requested, $remaining);
        $moved = [];

        foreach ($final as $position => $id) {
            $milestone = $milestones->get($id);

            if ($milestone === null || (int) $milestone->position === $position) {
                continue;
            }

            $moved[$id] = $position;
        }

        if ($moved === []) {
            return 0;
        }

        DB::transaction(function () use ($project, $moved, $final, $actor): void {
            foreach ($moved as $id => $position) {
                Milestone::query()
                    ->withoutWorkspaceScope()
                    ->whereKey($id)
                    ->update(['position' => $position]);
            }

            $this->activity->forUser($actor)->log($project, 'milestones_reordered', [
                'order' => array_values($final),
                'moved' => count($moved),
            ]);
        });

        event(new MilestonesReordered($project, array_values($final), $actor));

        return count($moved);
    }
}
