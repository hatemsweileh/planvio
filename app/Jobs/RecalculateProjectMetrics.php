<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Milestone;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\ProjectHealthCalculator;
use App\Services\ProjectProgressCalculator;
use App\Support\CurrentWorkspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * The nightly correction pass over the denormalised numbers: `projects.progress`,
 * `milestones.progress` and `projects.health`.
 *
 * These are caches, and every cache drifts. Progress is refreshed by the action that closes
 * a task, but a task deleted straight from the admin panel, a restored project, an import,
 * or a job that failed halfway leaves the stored percentage a little wrong — and health is
 * time-dependent in a way no write can trigger: a project becomes at-risk because a date
 * passed while nobody touched it.
 *
 * Both calculators work in bulk on purpose, so this is a fixed handful of queries per
 * workspace rather than a handful per project. Health respects `health_set_manually`: a
 * value somebody pinned by hand is never overwritten here.
 *
 * Archived projects are skipped. Their numbers are frozen by definition, and recomputing
 * them is work with no reader.
 */
final class RecalculateProjectMetrics implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Projects handled per batch. Bulk means bulk, but not unbounded memory. */
    private const CHUNK = 100;

    public function __construct(private readonly ?int $workspaceId = null) {}

    /**
     * One nightly pass at a time; a second dispatch while the first is still running is
     * dropped rather than queued behind it.
     */
    public function uniqueId(): string
    {
        return 'planvio:metrics:'.($this->workspaceId ?? 'all');
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    /**
     * @return array{projects: int, milestones: int}
     */
    public function handle(
        CurrentWorkspace $workspaces,
        ProjectProgressCalculator $progress,
        ProjectHealthCalculator $health,
    ): array {
        $counted = ['projects' => 0, 'milestones' => 0];

        foreach ($this->workspaces() as $workspace) {
            /** @var array{projects: int, milestones: int} $result */
            $result = $workspaces->runFor(
                $workspace,
                fn (): array => $this->forWorkspace($progress, $health),
            );

            $counted['projects'] += $result['projects'];
            $counted['milestones'] += $result['milestones'];
        }

        return $counted;
    }

    /**
     * @return array{projects: int, milestones: int}
     */
    private function forWorkspace(ProjectProgressCalculator $progress, ProjectHealthCalculator $health): array
    {
        $projects = 0;
        $milestones = 0;

        Project::query()
            ->active()
            ->chunkById(self::CHUNK, function ($batch) use (&$projects, &$milestones, $progress, $health): void {
                $ids = $batch->modelKeys();

                $progress->recalculateMany($ids);
                $health->applyMany($batch);

                $milestoneIds = Milestone::query()
                    ->whereIn('project_id', $ids)
                    ->pluck('id')
                    ->all();

                if ($milestoneIds !== []) {
                    $progress->recalculateMilestones(array_map(intval(...), $milestoneIds));
                    $milestones += count($milestoneIds);
                }

                $projects += $batch->count();
            });

        return ['projects' => $projects, 'milestones' => $milestones];
    }

    /**
     * @return iterable<Workspace>
     */
    private function workspaces(): iterable
    {
        return Workspace::query()
            ->active()
            ->when($this->workspaceId !== null, fn (Builder $query): Builder => $query->whereKey($this->workspaceId))
            ->orderBy('id')
            ->cursor();
    }
}
