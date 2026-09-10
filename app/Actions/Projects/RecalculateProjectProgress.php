<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ProjectProgressRecalculated;
use App\Models\Project;
use App\Services\ActivityLogger;
use App\Services\ProjectProgress;
use App\Services\ProjectProgressCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Refreshes the denormalised `projects.progress` cache.
 *
 * The percentage itself is not defined here: {@see ProjectProgressCalculator} owns the one
 * definition of progress in the product — completed tasks over total tasks, unweighted — and
 * a second definition living in an action would be the kind of drift where a board and a
 * report disagree about the same project. This action is the write path around it: it decides
 * *whether* to store, records the change, and announces it.
 *
 * Nothing is written when the number has not moved. This runs after every task status change,
 * so writing unconditionally would put a row in the activity feed every time somebody dragged
 * a card out of a column and back into it.
 *
 * The stored value is written without touching `updated_at`. Refreshing a cache is not a
 * change to the project, and bumping the timestamp would reshuffle every "recently modified"
 * list each time a task closed.
 */
final class RecalculateProjectProgress
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly ProjectProgressCalculator $calculator,
    ) {}

    /**
     * @param bool $silent skip the activity row — for bulk maintenance, where one feed
     *                     entry per project would drown the feed it is meant to explain
     */
    public function __invoke(Project $project, bool $silent = false): ProjectProgress
    {
        $progress = $this->calculator->forProject($project);
        $previous = (int) $project->progress;

        if ($previous === $progress->percentage) {
            return $progress;
        }

        DB::transaction(function () use ($project, $progress, $previous, $silent): void {
            $this->store($project, $progress->percentage);

            if ($silent) {
                return;
            }

            $this->activity->log($project, 'progress_recalculated', [
                'changes' => [
                    'progress' => ['old' => $previous, 'new' => $progress->percentage],
                ],
                'total' => $progress->total,
                'completed' => $progress->completed,
            ]);
        });

        event(new ProjectProgressRecalculated($project, $previous, $progress->percentage));

        return $progress;
    }

    /**
     * Write the column and bring the in-memory model back in step, so a caller that keeps
     * using `$project` does not hold a stale percentage.
     */
    private function store(Project $project, int $percentage): void
    {
        Project::query()
            ->withoutWorkspaceScope()
            ->whereKey($project->getKey())
            ->toBase()
            ->update(['progress' => $percentage]);

        $project->setAttribute('progress', $percentage);
        $project->syncOriginalAttribute('progress');
    }
}
